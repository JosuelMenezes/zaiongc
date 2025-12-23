export interface ApiConfig {
  baseUrl: string;   // e.g. http://127.0.0.1:8000/api
  token: string;     // sanctum token
}

export async function apiFetch<T>(cfg: ApiConfig, path: string, init?: RequestInit): Promise<T> {
  const res = await fetch(`${cfg.baseUrl}${path}`, {
    ...init,
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${cfg.token}`,
      ...(init?.headers ?? {})
    }
  });

  if (!res.ok) {
    const text = await res.text().catch(() => '');
    const err = new Error(`API ${res.status} ${res.statusText}: ${text}`);
    (err as any).status = res.status;
    (err as any).body = text;
    throw err;
  }

  // Alguns endpoints podem retornar null/empty
  const contentType = res.headers.get('content-type') || '';
  if (!contentType.includes('application/json')) return (null as any);

  return res.json();
}
