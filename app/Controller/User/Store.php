<?php
declare(strict_types=1);

namespace App\Controller\User;

use App\Controller\Base\View\User;
use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Util\StoreContentService;
use Kernel\Annotation\Interceptor;

#[Interceptor([Waf::class, UserVisitor::class])]
class Store extends User
{
    private function content(string $title, string $type, array $entries = [], array $page = []): string
    {
        // Saved article titles are authored content; fixed page headings use the UI dictionary.
        $authoredTitle = $type === 'helpArticle' && isset($page['contentLocale']);
        if (!$authoredTitle) $title = \Kernel\Util\Lang::trans($title, 'tpl');
        if ($authoredTitle && \App\Util\StoreLocale::get() === 'vi' && $page['contentLocale'] !== 'vi') {
            $page['robots'] = 'noindex,follow';
        }
        return $this->theme($title, 'CONTENT', 'Index/Content.html', [
            'authoredTitle' => $authoredTitle,
            'page' => array_merge(['title' => $title, 'body' => '', 'helpSummary' => '', 'missing' => false], $page, ['type' => $type]),
            'entries' => $entries, 'robots' => $page['robots'] ?? 'index,follow', 'seoDescription' => $page['summary'] ?? $title,
            'ogType' => $type === 'helpArticle' && empty($page['missing']) ? 'article' : 'website',
        ]);
    }

    public function sitemap(): string
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: no-cache, max-age=0');
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            http_response_code(405);
            header('Allow: GET, HEAD');
            return '';
        }
        if ($method === 'HEAD') return '';
        return \App\Util\PublicSitemap::generate(\App\Util\Client::getUrl());
    }

    public function help(): string { return $this->content('帮助中心', 'help', StoreContentService::helpArticles()); }

    public function sitemapPage(): string
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            http_response_code(405);
            header('Allow: GET, HEAD');
            return '';
        }
        if ($method === 'HEAD') return '';
        $locale = \App\Util\StoreLocale::get();
        return $this->content(\App\Util\PublicSitemap::labels($locale)['title'], 'sitemap', [], [
            'body' => new \App\Util\Html(\App\Util\PublicSitemap::html(\App\Util\PublicSitemap::catalog(), $locale)),
        ]);
    }
    public function contact(): string
    {
        $bundle = StoreContentService::bundle();
        return $this->articleRedirect((array)($bundle['contactArticle'] ?? []), (array)($bundle['helpArticles'] ?? []));
    }
    public function articles(): string { return $this->content('文章', 'articles', StoreContentService::bundle()['articles']); }

    public function closed(): string
    {
        return $this->theme('店铺正在维护', 'CLOSED', 'Index/Closed.html', ['robots' => 'noindex,nofollow']);
    }

    private function single(string $type, string $slug): string
    {
        $key = $type === 'article' ? 'articles' : 'policies';
        foreach (StoreContentService::bundle()[$key] as $entry) {
            if ($entry['slug'] === $slug) return $this->content($entry['title'], $type, [], $entry);
        }
        http_response_code(404);
        return $this->content('内容不存在', 'missing', [], ['robots' => 'noindex,nofollow']);
    }

    public function article(): string { return $this->single('article', (string)($_GET['slug'] ?? '')); }

    public function helpArticle(): string
    {
        $slug = (string)($_GET['slug'] ?? '');
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,95}$/D', $slug)) {
            foreach (StoreContentService::helpArticles() as $entry) {
                if ($entry['slug'] === $slug) return $this->content($entry['title'], 'helpArticle', [], $entry);
            }
        }
        http_response_code(404);
        return $this->content('内容不存在', 'helpArticle', [], ['missing' => true, 'robots' => 'noindex,nofollow']);
    }

    public function policy(): string
    {
        $slug = (string)($_GET['slug'] ?? '');
        $bundle = StoreContentService::bundle();
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,95}$/D', $slug)) {
            foreach ($bundle['policies'] ?? [] as $entry) {
                if (($entry['slug'] ?? '') === $slug && (int)($entry['targetArticleId'] ?? 0) > 0) return $this->articleRedirect($entry, (array)($bundle['helpArticles'] ?? []));
            }
        }
        return $this->missingArticle();
    }

    /** Legacy presentation aliases only ever redirect to a currently eligible article. */
    private function articleRedirect(array $reference, array $articles): string
    {
        $id = (int)($reference['targetArticleId'] ?? $reference['id'] ?? 0);
        $slug = (string)($reference['articleSlug'] ?? $reference['slug'] ?? '');
        if ($id > 0 && preg_match('/^[a-z0-9][a-z0-9_-]{0,95}$/D', $slug)) {
            foreach ($articles as $article) {
                if ((int)($article['id'] ?? 0) !== $id || ($article['slug'] ?? '') !== $slug) continue;
                // Construct from the verified source identity, never from an arbitrary saved URL.
                header('Location: ' . \App\Util\StoreLocale::url('/help/' . $slug), true, 301);
                header('Cache-Control: no-cache, max-age=0');
                return '';
            }
        }
        return $this->missingArticle();
    }

    private function missingArticle(): string
    {
        http_response_code(404);
        return $this->content('内容不存在', 'helpArticle', [], ['missing' => true, 'robots' => 'noindex,nofollow']);
    }
}
