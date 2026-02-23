export type BookEntry = {
  bookId: string;
  chapterNumber: number;
  chapterUrl: string;
  demoEndPage: number;
  ctaLabel: string;
  ctaSubtitle?: string;
};

export const DEMO_BOOK_ID = 'alice-001';

const BOOKS: Record<string, BookEntry> = {
  'alice-001': {
    bookId: 'alice-001',
    chapterNumber: 1,
    chapterUrl: '/mock/alice-001-ch1.json',
    demoEndPage: 6,
    ctaLabel: "Start Alice's Adventures in Wonderland",
    ctaSubtitle: '开始爱丽丝梦游仙境'
  }
};

export const getBookEntry = (bookId: string) => BOOKS[bookId] ?? null;
