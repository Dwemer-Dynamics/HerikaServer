<?php
require_once dirname(__DIR__, 2) . '/lib/dynamic_profile_scheduler.php';

// Reuse the same labels and bounds in the NPC-profile and narrator web editors.
function dps_render_controls(array $metadata, bool $narrator = false): void {
    $policy = dps_policy($metadata);
    $fields = [
        'DYNAMIC_PROFILE_INTERVAL_DAYS'=>['Update every (game days)', 1/24, 365, 'any'],
        'DYNAMIC_PROFILE_MIN_EVENTS'=>['Minimum new events', 1, 10000, 1],
        'DYNAMIC_PROFILE_COOLDOWN_MINUTES'=>['Cooldown (real minutes)', 1, 1440, 1],
    ];
    ?>
    <div class="dynamic-profile-schedule" style="margin:12px 0">
        <p class="setting-desc"><?= $narrator ? 'The narrator updates when all three conditions are met.' : 'Each NPC updates when all three conditions are met, wherever they are in the game. Events count separately for each NPC.' ?></p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,180px),1fr));gap:12px">
            <?php foreach ($fields as $key=>[$label,$min,$max,$step]): ?>
            <label style="display:flex;flex-direction:column;gap:6px">
                <span><?= htmlspecialchars($label) ?></span>
                <input type="number" name="<?= $narrator ? 'dynamic_schedule' : 'meta_vis' ?>[<?= $key ?>]"
                    value="<?= htmlspecialchars((string)$policy[$key]) ?>" min="<?= $min ?>" max="<?= $max ?>" step="<?= $step ?>" required style="width:100%;box-sizing:border-box">
            </label>
            <?php endforeach; ?>
        </div>
        <p class="setting-desc">The cooldown also applies after a failed attempt.</p>
    </div>
    <?php
}
