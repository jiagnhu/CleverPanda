const normalizeBaseUrl = (value: string) => {
  const trimmed = value.trim();
  if (!trimmed) return '';
  return trimmed.endsWith('/') ? trimmed.slice(0, -1) : trimmed;
};

const API_BASE_URL = normalizeBaseUrl(import.meta.env.VITE_API_BASE_URL || '');

if (import.meta.env.DEV && !API_BASE_URL) {
  console.warn('[api] VITE_API_BASE_URL is empty. API calls will use relative /api paths.');
}

export const buildApiUrl = (path: string) => {
  if (path.startsWith('http://') || path.startsWith('https://')) {
    return path;
  }
  if (!path.startsWith('/')) {
    throw new Error(`api_path_must_start_with_slash: ${path}`);
  }
  return API_BASE_URL ? `${API_BASE_URL}${path}` : path;
};

export const postJson = async (path: string, payload: Record<string, unknown>) => {
  const response = await fetch(buildApiUrl(path), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });
  return response;
};
