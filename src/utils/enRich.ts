import { canonicalize } from '@/utils/tokenize';

export type WordColor = 'green' | 'blue';

export type EnRichSegment =
  | {
      kind: 'text';
      text: string;
    }
  | {
      kind: 'word';
      text: string;
      canonical: string;
      color: WordColor;
    };

const ENRICH_TOKEN_REGEX = /\{([gb])\|([^{}]+)\}/g;

export const parseEnRichLine = (line: string): EnRichSegment[] => {
  if (!line || !line.includes('{')) {
    return [{ kind: 'text', text: line }];
  }

  const segments: EnRichSegment[] = [];
  let cursor = 0;

  line.replace(ENRICH_TOKEN_REGEX, (match, colorToken: string, rawText: string, offset: number) => {
    if (offset > cursor) {
      segments.push({
        kind: 'text',
        text: line.slice(cursor, offset)
      });
    }

    const text = rawText;
    const canonical = canonicalize(rawText);
    const color: WordColor = colorToken === 'b' ? 'blue' : 'green';

    if (canonical) {
      segments.push({
        kind: 'word',
        text,
        canonical,
        color
      });
    } else {
      segments.push({
        kind: 'text',
        text
      });
    }

    cursor = offset + match.length;
    return match;
  });

  if (cursor < line.length) {
    segments.push({
      kind: 'text',
      text: line.slice(cursor)
    });
  }

  if (!segments.length) {
    return [{ kind: 'text', text: line }];
  }

  return segments;
};

export const collectEnRichWords = (line: string): string[] => {
  const segments = parseEnRichLine(line);
  return segments
    .filter((segment): segment is Extract<EnRichSegment, { kind: 'word' }> => segment.kind === 'word')
    .map((segment) => segment.canonical);
};

