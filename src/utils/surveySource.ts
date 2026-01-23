export type SurveySource = 'internal' | 'fiverr' | 'unknown';

const SOURCE_KEY = 'cp_survey_source';

const normalizeSource = (value: string | null | undefined): SurveySource => {
  const normalized = (value ?? '').trim().toLowerCase();
  if (normalized === 'internal' || normalized === 'fiverr') {
    return normalized;
  }
  return 'unknown';
};

export const initSurveySource = (): SurveySource => {
  try {
    const params = new URLSearchParams(window.location.search);
    const param = params.get('src');
    if (param !== null) {
      const source = normalizeSource(param);
      localStorage.setItem(SOURCE_KEY, source);
      return source;
    }
    const stored = localStorage.getItem(SOURCE_KEY);
    if (stored) {
      const source = normalizeSource(stored);
      localStorage.setItem(SOURCE_KEY, source);
      return source;
    }
    localStorage.setItem(SOURCE_KEY, 'unknown');
    return 'unknown';
  } catch {
    return 'unknown';
  }
};

export const getSurveySource = (): SurveySource => {
  try {
    const stored = localStorage.getItem(SOURCE_KEY);
    return normalizeSource(stored);
  } catch {
    return 'unknown';
  }
};
