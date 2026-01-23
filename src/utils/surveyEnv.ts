export type SurveyEnv = 'test' | 'mvp' | 'unknown';

export const getSurveyEnv = (): SurveyEnv => {
  const raw = (import.meta.env.VITE_APP_ENV || '').toString().trim().toLowerCase();
  if (raw === 'test' || raw === 'mvp') {
    return raw;
  }
  return 'unknown';
};
