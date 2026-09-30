<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    /**
     * Render a template inside a layout and send it to the browser.
     */
    public static function render(string $template, array $data = [], string $layout = 'app'): void
    {
        $view = new self();
        $content = $view->capture($template, $data);
        echo $view->capture("layouts/{$layout}", array_merge($data, ['content' => $content]));
    }

    /**
     * Render a template to a string (used for partials / AJAX fragments).
     */
    public static function partial(string $template, array $data = []): string
    {
        return (new self())->capture($template, $data);
    }

    private function capture(string $template, array $data = []): string
    {
        $file = dirname(__DIR__) . '/Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View nicht gefunden: {$template}");
        }

        extract($data, EXTR_SKIP);

        ob_start();
        include $file;

        return (string) ob_get_clean();
    }
}
