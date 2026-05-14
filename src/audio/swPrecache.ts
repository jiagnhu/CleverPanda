/** 与 ReadingPage 一致：把章节 manifest 转成绝对 URL，供 SW `precache-audio` 使用 */

export function buildAudioPrecacheUrls(
  baseUrl: string,
  manifest: Record<string, string>
): string[] {
  const trimmed = baseUrl.trim();
  if (!trimmed) return [];
  const fileNames = Array.from(
    new Set(
      Object.values(manifest).filter((fileName) => fileName.trim() !== '')
    )
  );
  if (!fileNames.length) return [];

  const normalized = trimmed.endsWith('/') ? trimmed : `${trimmed}/`;
  const base = new URL(normalized, window.location.origin);
  return fileNames.map((fileName) => new URL(fileName.trim(), base).toString());
}

export async function requestSwAudioPrecache(urls: string[], cacheKey: string): Promise<void> {
  if (!urls.length || !('serviceWorker' in navigator)) return;
  const registration = await navigator.serviceWorker.ready;
  if (!registration.active) return;
  registration.active.postMessage({
    type: 'precache-audio',
    urls,
    cacheKey: cacheKey.trim() || 'default'
  });
}
