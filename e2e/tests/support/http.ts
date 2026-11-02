import { request, type APIRequestContext } from '@playwright/test';

export interface FetchedRoute {
  status: number;
  location: string | null;
  contentType: string | null;
  body: string;
}

/** HTTP client for crawl-style checks. Redirects are NOT followed, so the redirect itself is recorded. */
export class CrawlClient {
  private constructor(private readonly ctx: APIRequestContext) {}

  static async create(storageState?: string): Promise<CrawlClient> {
    return new CrawlClient(await request.newContext({ baseURL: process.env.BASE_URL, storageState }));
  }

  async get(path: string): Promise<FetchedRoute> {
    const res = await this.ctx.get(path, { maxRedirects: 0, failOnStatusCode: false });
    const headers = res.headers();
    return {
      status: res.status(),
      location: headers['location'] ?? null,
      contentType: headers['content-type'] ?? null,
      body: await res.text(),
    };
  }

  async dispose(): Promise<void> {
    await this.ctx.dispose();
  }
}
