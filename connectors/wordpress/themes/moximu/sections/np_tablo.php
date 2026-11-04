<?php
/** Tablo: sütun başlıkları ve satırlar "a | b | c" biçiminde (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<?= mx_np_open($d, 'aramalar') ?>
<?= mx_np_head($d) ?>
<div class="np-kw-w"><table class="np-kw"><thead><tr><?php foreach (mx_np_pair((string) ($d['sutunlar'] ?? '')) as $mx_c) : ?><th><?= mx_h($mx_c) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach (mx_np_lines($d['satirlar'] ?? '') as $mx_l) : ?><tr><?php foreach (mx_np_pair($mx_l) as $mx_c) : ?><td><?= mx_h($mx_c) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div>
</div></section>
