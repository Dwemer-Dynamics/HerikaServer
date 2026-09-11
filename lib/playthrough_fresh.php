<?php

// Fresh starts and snapshots share one reviewed table classification.
function pth_fresh_policy(): array {
    return ['empty' => pts_playthrough_tables()];
}

// Reset a private copy; activation preserves global tables and global settings rows.
function pth_prepare_fresh($conn, string $stage): void {
    $product = ptp_product(); $meta = $product['meta'];
    if (pg_transaction_status($conn) !== PGSQL_TRANSACTION_INTRANS
        || !preg_match('/^' . preg_quote($product['prefix'], '/') . 'upgrade_[0-9]+_[0-9]+$/D', $stage)) {
        throw new RuntimeException('Invalid fresh playthrough preparation.');
    }
    $schema = pg_escape_identifier($conn, $stage);
    $tables = array_column(pg_fetch_all(pth_query($conn, 'SELECT tablename FROM pg_tables WHERE schemaname=$1', [$stage])) ?: [], 'tablename');
    foreach (array_intersect(pth_fresh_policy()['empty'], $tables) as $table) {
        pth_query($conn, 'DELETE FROM ' . $schema . '.' . pg_escape_identifier($conn, $table));
    }
    // Recheck external references before any empty gameplay rows become active.
    pth_query($conn, "SELECT {$meta}.validate_playthrough($1, ARRAY(SELECT jsonb_array_elements_text($2::jsonb)))", [$stage,json_encode(pts_playthrough_tables())]);
}
