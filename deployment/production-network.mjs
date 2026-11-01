import { createHash, X509Certificate } from "node:crypto";
import { lookup } from "node:dns/promises";
import net from "node:net";
import tls from "node:tls";

export function validateTrust(env, policy, pem, now = Date.now()) {
  const id = policy.identity;
  if (!id || id.instance_name !== "KPS-Internal" || id.branch !== "main" || id.mapping_reviewed !== true ||
      !/^\d{6,30}$/.test(id.instance_id ?? "") || !/^[A-Za-z0-9]{1,64}$/.test(id.username_prefix ?? "") ||
      !id.console_evidence || !Number.isFinite(Date.parse(id.verified_at)) || Date.parse(id.verified_at) > now ||
      now - Date.parse(id.verified_at) > 30 * 86400_000 ||
      !/^[a-z0-9][a-z0-9.-]+\.tidbcloud\.com$/.test(env.PRODUCTION_DB_HOST ?? "") ||
      env.PRODUCTION_DB_NAME !== "kps_internal" || !/^\d{1,5}$/.test(env.PRODUCTION_DB_PORT ?? "") ||
      Number(env.PRODUCTION_DB_PORT) < 1 || Number(env.PRODUCTION_DB_PORT) > 65535 ||
      env.PRODUCTION_RO_USERNAME !== `${id.username_prefix}.kps_ci_reader` ||
      env.PRODUCTION_MIGRATOR_USERNAME !== `${id.username_prefix}.kps_ci_migrator`) {
    throw new Error("Production console instance/main binding is missing, stale or inconsistent.");
  }
  const fingerprint = createHash("sha256").update([env.PRODUCTION_DB_HOST, env.PRODUCTION_DB_PORT,
    "kps_internal", id.username_prefix, "main"].join("|")).digest("hex");
  if (id.endpoint_sha256 !== fingerprint || !/^[a-f0-9]{64}$/.test(id.server_hostname_sha256 ?? "")) {
    throw new Error("Production endpoint/SQL identity fingerprints are not reviewed.");
  }
  const caHash = createHash("sha256").update(pem).digest("hex");
  if (caHash !== env.PRODUCTION_DB_CA_SHA256) throw new Error("Production CA SHA-256 mismatch.");
  let certificate;
  try { certificate = new X509Certificate(pem); } catch { throw new Error("Production CA PEM is invalid."); }
  if (!certificate.ca || Date.parse(certificate.validFrom) > now || Date.parse(certificate.validTo) <= now) {
    throw new Error("Production CA is not a currently valid CA certificate.");
  }
  return caHash;
}

export function sslRequest(packet) {
  const length = packet.readUIntLE(0, 3);
  const payload = packet.subarray(4, length + 4);
  const terminator = payload.indexOf(0, 1);
  const capabilities = terminator + 1 + 4 + 8 + 1;
  if (payload[0] !== 10 || terminator < 2 || payload.length < capabilities + 7 ||
      !(payload.readUInt16LE(capabilities) & 0x0800)) {
    throw new Error("MySQL server does not advertise the required TLS handshake.");
  }
  const request = Buffer.alloc(36);
  request.writeUIntLE(32, 0, 3);
  request[3] = 1;
  request.writeUInt32LE(0x00008a00, 4); // PROTOCOL_41 | SSL | SECURE_CONNECTION
  request.writeUInt32LE(16 * 1024 * 1024, 8);
  request[12] = 45;
  return request;
}

// Authentication-free MySQL TLS upgrade, before either SQL credential is used.
// No firewall mutations, IP-range expansion or certificate-verification fallback.
export async function probeProductionNetwork(env, pem, { resolveDns = lookup, connect = net.connect, secureConnect = tls.connect } = {}) {
  try { await resolveDns(env.PRODUCTION_DB_HOST); } catch { throw new Error("Production network DNS check failed; details redacted."); }
  await new Promise((resolve, reject) => {
    let socket;
    let secure;
    let settled = false;
    const timer = setTimeout(() => finish(new Error("Production TCP/TLS probe timed out; check approved runner egress.")), 10_000);
    const finish = (error) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      secure?.destroy();
      socket?.destroy();
      if (error) reject(error); else resolve();
    };
    socket = connect({ host: env.PRODUCTION_DB_HOST, port: Number(env.PRODUCTION_DB_PORT) });
    socket.once("error", () => finish(new Error("Production TCP connection failed; check approved egress/access list. Details redacted.")));
    let greeting = Buffer.alloc(0);
    const receive = (data) => {
      greeting = Buffer.concat([greeting, data]);
      if (greeting.length > 4096) return finish(new Error("MySQL greeting exceeds the probe bound."));
      if (greeting.length < 4 || greeting.length < greeting.readUIntLE(0, 3) + 4) return;
      socket.removeListener("data", receive);
      try {
        socket.write(sslRequest(greeting));
        secure = secureConnect({ socket, servername: env.PRODUCTION_DB_HOST, ca: pem,
          rejectUnauthorized: true, minVersion: "TLSv1.2", checkServerIdentity: tls.checkServerIdentity });
        secure.once("error", () => finish(new Error("Production TLS CA/hostname verification failed; no insecure fallback.")));
        secure.once("secureConnect", () => {
          if (!secure.authorized) return finish(new Error("Production TLS peer is unauthorized."));
          finish();
        });
      } catch { finish(new Error("Production MySQL TLS upgrade failed; details redacted.")); }
    };
    socket.on("data", receive);
  });
  return { dns: "PASS", tcp: "PASS", tls: "PASS", ca_verified: true, hostname_verified: true };
}
