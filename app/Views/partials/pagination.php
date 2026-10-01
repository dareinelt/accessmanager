<?php
/** @var int $total */
/** @var int $page */
/** @var int $pageSize */
/** @var string $baseUrl */
$pageSize = max(1, (int) $pageSize);
$totalPages = max(1, (int) ceil($total / $pageSize));
if ($totalPages <= 1) {
    return;
}
$page = min(max(1, (int) $page), $totalPages);
$qs = $_GET;
$link = static function (int $p) use ($qs, $baseUrl): string {
    $qs['page'] = $p;
    return $baseUrl . '?' . http_build_query($qs);
};
// UX FIX: render a window around the current page instead of every page
// (thousands of links for large datasets); add accessible labels.
$window = 2;
$pages = [1, $totalPages];
for ($p = max(1, $page - $window); $p <= min($totalPages, $page + $window); $p++) {
    $pages[] = $p;
}
$pages = array_values(array_unique($pages));
sort($pages);
?>
<nav class="pagination" aria-label="Seitennavigation">
    <span class="muted"><?= (int) $total ?> Einträge · Seite <?= $page ?> von <?= $totalPages ?></span>
    <?php if ($page > 1): ?>
        <a href="<?= e($link($page - 1)) ?>" rel="prev" aria-label="Vorherige Seite">&lsaquo;</a>
    <?php endif; ?>
    <?php $prev = 0; foreach ($pages as $p): ?>
        <?php if ($p - $prev > 1): ?><span class="muted" aria-hidden="true">…</span><?php endif; ?>
        <?php if ($p === $page): ?>
            <span class="current" aria-current="page"><?= $p ?></span>
        <?php else: ?>
            <a href="<?= e($link($p)) ?>" aria-label="Seite <?= $p ?>"><?= $p ?></a>
        <?php endif; ?>
    <?php $prev = $p; endforeach; ?>
    <?php if ($page < $totalPages): ?>
        <a href="<?= e($link($page + 1)) ?>" rel="next" aria-label="Nächste Seite">&rsaquo;</a>
    <?php endif; ?>
</nav>
