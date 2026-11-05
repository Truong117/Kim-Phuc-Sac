import { useEffect, useState } from "react";
import {
  getIntegrations,
  getIntegrationOwners,
  saveIntegration,
  deleteIntegration,
  type IntegrationOwner,
} from "@/services/integrationService";
import type { IntegrationEntry } from "@/components/integrations/model";
export function useIntegrations() {
  const [entries, setEntries] = useState<IntegrationEntry[]>([]);
  const [owners, setOwners] = useState<IntegrationOwner[]>([]);
  const [canManage, setCanManage] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [revision, setRevision] = useState(0);
  useEffect(() => {
    const abort = new AbortController();
    Promise.all([
      getIntegrations(abort.signal),
      getIntegrationOwners(abort.signal),
    ])
      .then(([list, references]) => {
        if (abort.signal.aborted) return;
        setEntries(list.data);
        setOwners(references.data);
        setCanManage(list.actions.can_manage);
        setError(false);
      })
      .catch(() => {
        if (!abort.signal.aborted) {
          setError(true);
          setEntries([]);
          setOwners([]);
          setCanManage(false);
        }
      })
      .finally(() => {
        if (!abort.signal.aborted) setLoading(false);
      });
    return () => abort.abort();
  }, [revision]);
  function reload() {
    setLoading(true);
    setError(false);
    setRevision((value) => value + 1);
  }
  async function save(entry: IntegrationEntry) {
    const { data } = await saveIntegration(entry);
    setEntries((previous) =>
      entry.id
        ? previous.map((row) => (row.id === data.id ? data : row))
        : [...previous, data],
    );
  }
  async function remove(entry: IntegrationEntry) {
    await deleteIntegration(entry);
    setEntries((previous) => previous.filter((row) => row.id !== entry.id));
  }
  return { entries, owners, canManage, loading, error, reload, save, remove };
}
