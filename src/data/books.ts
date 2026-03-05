export type DemoConfig = {
  contentUrl: string;
  endPage: number;
  targetBookId: string;
};

export type BookCatalogChapterRef = {
  number: number;
  contentUrl: string;
  available?: boolean;
};

export type BookCatalogEntry = {
  bookId: string;
  chapters: BookCatalogChapterRef[];
};

export type BookCatalog = {
  demo?: DemoConfig;
  books: BookCatalogEntry[];
};

export type BookContentChapter = {
  number?: number;
  titleEnglish?: string;
  titleMandarin?: string;
  totalPages?: number;
  pages?: unknown[];
};

export type BookContentPayload = {
  titleEnglish?: string;
  titleMandarin?: string;
  chapter?: BookContentChapter | BookContentChapter[];
  chapters?: BookContentChapter[];
};

const CATALOG_URL = '/mock/books.json';

let catalogPromise: Promise<BookCatalog | null> | null = null;

export const fetchBookCatalog = async (): Promise<BookCatalog | null> => {
  if (!catalogPromise) {
    catalogPromise = fetch(CATALOG_URL, { cache: 'no-store' })
      .then(async (response) => {
        if (!response.ok) return null;
        return (await response.json()) as BookCatalog;
      })
      .catch(() => null);
  }

  return catalogPromise;
};

export const getDemoConfig = async (): Promise<DemoConfig | null> => {
  const catalog = await fetchBookCatalog();
  return catalog?.demo ?? null;
};

export const getBookEntry = async (bookId: string): Promise<BookCatalogEntry | null> => {
  const catalog = await fetchBookCatalog();
  if (!catalog) return null;
  return catalog.books.find((book) => book.bookId === bookId) ?? null;
};

export const getBookChapterEntry = async (
  bookId: string,
  chapterNo: number
): Promise<BookCatalogChapterRef | null> => {
  const book = await getBookEntry(bookId);
  if (!book) return null;
  return book.chapters.find((chapter) => chapter.number === chapterNo) ?? null;
};

export const fetchBookContent = async (contentUrl: string): Promise<BookContentPayload | null> => {
  try {
    const response = await fetch(contentUrl, { cache: 'no-store' });
    if (!response.ok) return null;
    return (await response.json()) as BookContentPayload;
  } catch {
    return null;
  }
};

export const collectContentChapters = (payload: BookContentPayload): BookContentChapter[] => {
  if (Array.isArray(payload.chapter) && payload.chapter.length > 0) {
    return payload.chapter;
  }
  if (payload.chapter && !Array.isArray(payload.chapter)) {
    return [payload.chapter];
  }
  if (Array.isArray(payload.chapters) && payload.chapters.length > 0) {
    return payload.chapters;
  }
  return [];
};
