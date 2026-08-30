<?php

namespace App\Http\Controllers;

use App\Models\Article;
use Illuminate\View\View;

/**
 * Блог.
 *
 * Точка входу для тих, хто ще не шукає інструмент, а шукає, як зробити роботу.
 * Тому головне на сторінці статті — не текст, а перехід далі: у комплект під
 * задачу або в категорію, де все потрібне вже зібрано.
 */
class ArticleController extends Controller
{
    public function index(): View
    {
        return view('pages.blog', [
            'articles' => Article::with('kit')->orderBy('position')->get(),
        ]);
    }

    public function show(Article $article): View
    {
        return view('pages.article', [
            'article' => $article->load(['kit.items.product', 'category']),
            // «Читайте також» веде вглиб сайту, а не в нікуди: робот ходить
            // по внутрішніх посиланнях так само, як людина.
            'others' => Article::where('id', '!=', $article->id)
                ->orderBy('position')->take(3)->get(),
        ]);
    }
}
