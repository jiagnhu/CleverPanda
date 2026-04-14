import { parseEnRichLine } from '@/utils/enRich';
import { canonicalize, tokenize } from '@/utils/tokenize';

/**
 * 词位（position）是完成率的最小统计单位。
 *
 * 为什么不用“单词去重”：
 * - 同一个词在一章里出现 10 次，本质上是 10 个阅读节点，不是 1 个
 * - 完成率衡量的是“内容推进程度”，不是“词汇覆盖种类数”
 */
export type AnalyticsChapterItem = {
  id: string;
  pageNo: number;
  enLines: string[];
};

export type ChapterContentRegistration = {
  bookId: string;
  chapterNo: number;
  contentVersion: string;
  totalInteractivePositions: number;
  contentUrl?: string;
};

export const buildInteractivePositionKey = (
  pageNo: number,
  lineIndex: number,
  interactiveIndexInLine: number
) => `p${pageNo}-l${lineIndex}-i${interactiveIndexInLine}`;

const getLineInteractiveCount = (line: string, interactiveSet: Set<string>) => {
  if (!line) return 0;

  if (line.includes('{g|') || line.includes('{b|')) {
    return parseEnRichLine(line).filter((segment) => segment.kind === 'word').length;
  }

  return tokenize(line).reduce((count, token) => {
    const canonical = canonicalize(token);
    if (!canonical || !interactiveSet.has(canonical)) return count;
    return count + 1;
  }, 0);
};

export const countChapterInteractivePositions = (
  items: AnalyticsChapterItem[],
  interactiveSet: Set<string>
) =>
  items.reduce((chapterCount, item) => {
    return (
      chapterCount +
      item.enLines.reduce((lineCount, line) => lineCount + getLineInteractiveCount(line, interactiveSet), 0)
    );
  }, 0);

/**
 * 这里不用加密哈希，只需要一个稳定、可复现、可比较的内容版本标识。
 * 目的不是安全，而是区分“这一章内容有没有改过”。
 */
const createStableHash = (input: string) => {
  let hash = 5381;
  for (let index = 0; index < input.length; index += 1) {
    hash = ((hash << 5) + hash) ^ input.charCodeAt(index);
  }
  return (hash >>> 0).toString(36);
};

export const createChapterContentVersion = (
  bookId: string,
  chapterNo: number,
  items: AnalyticsChapterItem[]
) => {
  const signature = items
    .map((item) => `${item.id}|${item.pageNo}|${item.enLines.join('\n')}`)
    .join('||');

  return `cv_${bookId || 'unknown'}_${chapterNo}_${createStableHash(signature)}`;
};
