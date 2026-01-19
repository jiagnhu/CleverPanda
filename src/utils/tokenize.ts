const TOKEN_REGEX = /\s+|[A-Za-z]+|[^\sA-Za-z]+/g;
const PUNCTUATION_REGEX =
  /^[\.,!?;:"'()\[\]{}\u2014-]+|[\.,!?;:"'()\[\]{}\u2014-]+$/g;

export function tokenize(text: string): string[] {
  return text.match(TOKEN_REGEX) ?? [];
}

export function canonicalize(token: string): string {
  const stripped = token.replace(PUNCTUATION_REGEX, '').trim();
  return stripped.toLowerCase();
}
