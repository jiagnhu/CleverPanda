import { canonicalize } from '@/utils/tokenize';

let audio: HTMLAudioElement | null = null;
let lastPlayTime = 0;
const THROTTLE_MS = 150;
let preferredVoice: SpeechSynthesisVoice | null = null;
let voicesInitialized = false;
let useLocalAudio = true;
let audioBaseUrl = '/audio/';
let audioManifest: Record<string, string> = {};

type AudioConfig = {
  baseUrl?: string;
  manifest?: Record<string, string> | null;
};

function normalizeBaseUrl(url: string): string {
  if (!url) return '/audio/';
  return url.endsWith('/') ? url : `${url}/`;
}

function getAudio(): HTMLAudioElement {
  if (!audio) {
    audio = new Audio();
  }
  return audio;
}

function pickPreferredVoice(voices: SpeechSynthesisVoice[]): SpeechSynthesisVoice | null {
  if (!voices.length) return null;
  const enGb = voices.filter((voice) => voice.lang.toLowerCase().startsWith('en-gb'));
  const en = voices.filter((voice) => voice.lang.toLowerCase().startsWith('en'));
  const femaleRegex = /female|woman|girl/i;
  const preferredFemaleNames = ['flo', 'martha', 'sandy', 'shelley', 'grandma'];

  const nameMatch = (voice: SpeechSynthesisVoice) =>
    preferredFemaleNames.some((name) => voice.name.toLowerCase().includes(name));

  const pickFrom = (list: SpeechSynthesisVoice[]) =>
    list.find((voice) => femaleRegex.test(voice.name)) ?? list[0] ?? null;

  const pickNamed = (list: SpeechSynthesisVoice[]) =>
    list.find((voice) => nameMatch(voice)) ?? null;

  return pickNamed(enGb) ?? pickNamed(en) ?? pickFrom(enGb) ?? pickFrom(en);
}

function initVoices(): void {
  if (!('speechSynthesis' in window) || voicesInitialized) return;
  voicesInitialized = true;
  const synth = window.speechSynthesis;

  const applyVoices = () => {
    const voices = synth.getVoices();
    const picked = pickPreferredVoice(voices);
    if (picked) {
      preferredVoice = picked;
    }
  };

  applyVoices();
  synth.addEventListener('voiceschanged', applyVoices);
}

export function setUseLocalAudio(enabled: boolean): void {
  useLocalAudio = enabled;
  if (!useLocalAudio && audio) {
    audio.pause();
    audio.currentTime = 0;
  }
}

export function setAudioConfig(config: AudioConfig): void {
  if (config.baseUrl !== undefined) {
    audioBaseUrl = normalizeBaseUrl(config.baseUrl);
  }
  if (config.manifest !== undefined) {
    audioManifest = config.manifest ? { ...config.manifest } : {};
  }
}

function speakFallback(word: string): void {
  if (!('speechSynthesis' in window)) return;
  initVoices();
  window.speechSynthesis.cancel();
  const utterance = new SpeechSynthesisUtterance(word);
  utterance.lang = 'en-GB';
  if (preferredVoice) {
    utterance.voice = preferredVoice;
  }
  window.speechSynthesis.speak(utterance);
}

function tryPlayAudio(audioEl: HTMLAudioElement, src: string): Promise<void> {
  return new Promise((resolve, reject) => {
    let settled = false;

    const cleanup = () => {
      audioEl.removeEventListener('error', onError);
      audioEl.removeEventListener('playing', onPlaying);
    };

    const resolveOnce = () => {
      if (settled) return;
      settled = true;
      cleanup();
      resolve();
    };

    const rejectOnce = (err: Error) => {
      if (settled) return;
      settled = true;
      cleanup();
      reject(err);
    };

    const onError = () => rejectOnce(new Error('audio error'));
    const onPlaying = () => resolveOnce();

    audioEl.addEventListener('error', onError);
    audioEl.addEventListener('playing', onPlaying);

    audioEl.src = src;
    audioEl.load();

    const playPromise = audioEl.play();
    if (playPromise) {
      playPromise.then(resolveOnce).catch((err) => {
        rejectOnce(err instanceof Error ? err : new Error('audio play error'));
      });
    }
  });
}

export async function playWord(word: string): Promise<void> {
  const now = Date.now();
  if (now - lastPlayTime < THROTTLE_MS) return;
  lastPlayTime = now;

  const canonical = canonicalize(word);
  if (!canonical) return;

  if ('speechSynthesis' in window) {
    initVoices();
    window.speechSynthesis.cancel();
  }

  if (!useLocalAudio) {
    if (audio) {
      audio.pause();
      audio.currentTime = 0;
    }
    speakFallback(canonical);
    return;
  }

  const fileName = audioManifest[canonical];
  if (!fileName) {
    speakFallback(canonical);
    return;
  }
  const src = `${audioBaseUrl}${fileName}`;

  const audioEl = getAudio();
  audioEl.pause();
  audioEl.currentTime = 0;
  try {
    await tryPlayAudio(audioEl, src);
  } catch {
    speakFallback(canonical);
  }
}
