import assert from "node:assert/strict";
import { after, before, test } from "node:test";
import { execFileSync } from "node:child_process";
import { mkdtemp, readFile, writeFile, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { dirname, basename, join, resolve } from "node:path";
import net from "node:net";
import tls from "node:tls";
import { once } from "node:events";
import { probeProductionNetwork } from "../production-network.mjs";

let directory;
let ca;
let wrongCa;
let context;
before(async () => {
  directory = await mkdtemp(join(tmpdir(), "kps-network-test-"));
  await writeFile(join(directory, "openssl.cnf"), `[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=KPS LOCAL TEST\n[v3_ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n[server]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:localhost\n`);
  await writeFile(join(directory, "certificate.php"), `<?php
$dir = $argv[1];
$options = ['config'=>$dir.'/openssl.cnf','digest_alg'=>'sha256','private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA];
function rootCertificate(array $options): array {
  $key=openssl_pkey_new($options);
  $csr=openssl_csr_new(['commonName'=>'KPS LOCAL TEST CA'],$key,$options);
  $cert=openssl_csr_sign($csr,null,$key,1,$options+['x509_extensions'=>'v3_ca']);
  if (!$cert) throw new RuntimeException('local_certificate_setup_failed');
  return [$key,$cert];
}
[$key,$cert]=rootCertificate($options);
[$otherKey,$otherCert]=rootCertificate($options);
$leafKey=openssl_pkey_new($options);
$leafCsr=openssl_csr_new(['commonName'=>'localhost'],$leafKey,$options);
$leaf=openssl_csr_sign($leafCsr,$cert,$key,1,$options+['x509_extensions'=>'server'],42);
if (!$leaf) throw new RuntimeException('local_certificate_setup_failed');
openssl_x509_export($cert,$pem);file_put_contents($dir.'/ca.pem',$pem);
openssl_x509_export($otherCert,$pem);file_put_contents($dir.'/other-ca.pem',$pem);
openssl_x509_export($leaf,$pem);file_put_contents($dir.'/server.pem',$pem);
openssl_pkey_export($leafKey,$pem,null,$options);file_put_contents($dir.'/server.key',$pem);chmod($dir.'/server.key',0600);
`);
  const env = Object.fromEntries(["PATH", "SystemRoot", "WINDIR", "TEMP", "TMP"].filter((key) => process.env[key]).map((key) => [key, process.env[key]]));
  execFileSync("php", [join(directory, "certificate.php"), directory], { env, stdio: "pipe", timeout: 30_000 });
  ca = await readFile(join(directory, "ca.pem"));
  wrongCa = await readFile(join(directory, "other-ca.pem"));
  context = tls.createSecureContext({ key: await readFile(join(directory, "server.key")), cert: await readFile(join(directory, "server.pem")) });
});
after(async () => {
  if (directory) {
    assert.equal(resolve(dirname(directory)), resolve(tmpdir()));
    assert.ok(basename(directory).startsWith("kps-network-test-"));
    await rm(directory, { recursive: true, force: true });
  }
});

function greeting() {
  const payload = Buffer.alloc(64);
  payload[0] = 10;
  payload.write("8.0\0", 1);
  payload.writeUInt16LE(0x8a00, 18);
  const header = Buffer.alloc(4);
  header.writeUIntLE(payload.length, 0, 3);
  return Buffer.concat([header, payload]);
}

async function withServer(run) {
  const sockets = new Set();
  let authenticationBytes = 0;
  const server = net.createServer((socket) => {
    sockets.add(socket);
    socket.on("error", () => {});
    socket.once("close", () => sockets.delete(socket));
    socket.write(greeting());
    let request = Buffer.alloc(0);
    const receive = (data) => {
      request = Buffer.concat([request, data]);
      if (request.length < 36) return;
      socket.removeListener("data", receive);
      socket.pause();
      assert.equal(request.readUIntLE(0, 3), 32);
      assert.equal(request.readUInt32LE(4) & 0x0800, 0x0800);
      if (request.length > 36) socket.unshift(request.subarray(36));
      const secure = new tls.TLSSocket(socket, { isServer: true, secureContext: context });
      sockets.add(secure);
      secure.on("error", () => {});
      secure.on("data", (data) => { authenticationBytes += data.length; });
      secure.once("close", () => sockets.delete(secure));
    };
    socket.on("data", receive);
  });
  server.listen(0, "127.0.0.1");
  await once(server, "listening");
  try {
    await run(server.address().port);
    assert.equal(authenticationBytes, 0, "The network probe must never authenticate SQL.");
  } finally {
    for (const socket of sockets) socket.destroy();
    await new Promise((done) => server.close(done));
  }
}

test("actual localhost MySQL-to-TLS upgrade validates CA and hostname, sends no SQL authentication", async () => {
  await withServer(async (port) => {
    const evidence = await probeProductionNetwork({ PRODUCTION_DB_HOST: "localhost", PRODUCTION_DB_PORT: String(port) }, ca, {
      resolveDns: async () => ({ address: "127.0.0.1" }), connect: () => net.connect({ host: "127.0.0.1", port }),
    });
    assert.equal(evidence.hostname_verified, true);
  });
});

test("actual TLS rejects the wrong CA without an insecure fallback", async () => {
  await withServer(async (port) => {
    await assert.rejects(probeProductionNetwork({ PRODUCTION_DB_HOST: "localhost", PRODUCTION_DB_PORT: String(port) }, wrongCa, {
      resolveDns: async () => ({ address: "127.0.0.1" }), connect: () => net.connect({ host: "127.0.0.1", port }),
    }), /TLS CA\/hostname verification failed/);
  });
});

test("actual TLS rejects a hostname different from certificate SAN", async () => {
  await withServer(async (port) => {
    await assert.rejects(probeProductionNetwork({ PRODUCTION_DB_HOST: "wrong.example.test", PRODUCTION_DB_PORT: String(port) }, ca, {
      resolveDns: async () => ({ address: "127.0.0.1" }), connect: () => net.connect({ host: "127.0.0.1", port }),
    }), /TLS CA\/hostname verification failed/);
  });
});
