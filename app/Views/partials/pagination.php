<?php
/** @var int $total */
/** @var int $page */
/** @var int $pageSize */
/** @var string $baseUrl */
$totalPages = max(1, (int) ceil($total / $pageSize));
if ($totalPages <= 1) {
    return;
}
$qs = $_GET;
?>
<div class="pagination">
    <span class="muted"><?= (int) $total ?> Einträge</span>
    <?php for ($p = 1; $p <= $totalPages; $p++): $qs['page'] = $p; $href = $baseUrl . '?' . http_build_query($qs); ?>
        <?php if ($p === $page): ?>
            <span class="current"><?= $p ?></span>
        <?php else: ?>
            <a href="<?= e($href) ?>"><?= $p ?></a>
        <?php endif; ?>
    <?php endfor; ?>
</div>
