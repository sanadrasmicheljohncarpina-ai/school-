<?php
/* ======================================================================
 * PART 1 — POST handler. Put near the top of settings.php, after
 *   require_once 'security.php'; secure_session_start(); require_once 'db.php';
 * and after you know $userId (users.id) and $username of the logged-in user.
 * ====================================================================== */
$sqError = null; $sqSaved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_security_questions') {
    security_ensure_tables($conn);                       // use your mysqli variable name
    if (!csrf_valid($_POST['csrf_token'] ?? '')) {
        $sqError = 'Your session expired. Please reload the page and try again.';
    } else {
        $existing = sq_load($conn, $userId);
        [$sqError, $rows] = sq_validate($_POST['q'] ?? [], $_POST['a'] ?? [], $username, $existing);
        if (!$sqError) {
            $conn->begin_transaction();
            try { sq_save($conn, $userId, $rows); $conn->commit(); $sqSaved = true; }
            catch (Throwable $e) { $conn->rollback(); $sqError = 'Could not save. Please try again.'; }
        }
    }
}
// Always load AFTER the handler so the modal shows what is really stored.
security_ensure_tables($conn);
$sqState   = sq_ui_state($conn, $userId);               // no answers/hashes, only keys + flags
$sqActive  = sq_questions();
$sqAllLbl  = sq_all_questions();
?>

<?php
/* ======================================================================
 * PART 2 — Modal body. Keep YOUR existing wrapper, header, Cancel / Save
 * buttons and CSS classes; only the three question rows change.
 * The Save button must be inside (or linked via form="sqForm" to) this form.
 * ====================================================================== */
?>
<form method="post" id="sqForm" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="update_security_questions">
  <?php if ($sqError): ?><div class="alert-error"><?= htmlspecialchars($sqError) ?></div><?php endif; ?>

  <?php for ($slot = 1; $slot <= 3; $slot++):
        $cur = $sqState[$slot] ?? null; ?>
    <div class="sq-row" data-slot="<?= $slot ?>"
         data-orig-key="<?= htmlspecialchars($cur['key'] ?? '') ?>"
         data-has-answer="<?= !empty($cur['has_answer']) ? '1' : '0' ?>">
      <span class="sq-num"><?= $slot ?></span>

      <select name="q[<?= $slot ?>]" class="sq-select" required>
        <option value="">Choose a question</option>
        <?php if ($cur && $cur['retired']): /* old question: still shown/selected so it isn't silently lost */ ?>
          <option value="<?= htmlspecialchars($cur['key']) ?>" selected>
            <?= htmlspecialchars($cur['label']) ?> (no longer offered)
          </option>
        <?php endif; ?>
        <?php foreach ($sqActive as $key => $label): ?>
          <option value="<?= htmlspecialchars($key) ?>" <?= ($cur && $cur['key'] === $key) ? 'selected' : '' ?>>
            <?= htmlspecialchars($label) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <!-- Saved answer: shown as a filled mask. The real answer is never sent to the browser. -->
      <input type="text" name="a[<?= $slot ?>]" class="sq-answer" maxlength="100" autocomplete="off"
             placeholder="Your answer"
             value="<?= !empty($cur['has_answer']) ? '••••••••' : '' ?>">
    </div>
  <?php endfor; ?>

  <!-- keep your existing "Saving replaces..." notice, but reword it: -->
  <div class="sq-note">
    <strong>Only the questions you change are updated.</strong>
    Saved answers appear masked and stay as they are unless you replace them. For your security, they are never shown.
  </div>
  <!-- your existing Cancel + Save buttons; give Save id="sqSaveBtn" -->
</form>

<script>
(function () {
  const MASK = '••••••••';
  const form = document.getElementById('sqForm'), saveBtn = document.getElementById('sqSaveBtn');
  if (!form) return;
  const rows = [...form.querySelectorAll('.sq-row')];

  const isMask = el => el.value === MASK;
  const origQ  = row => row.dataset.origKey;
  const hadAns = row => row.dataset.hasAnswer === '1';

  function refresh() {
    const chosen = rows.map(r => r.querySelector('select').value);
    let dirty = false, valid = true;

    rows.forEach((row, i) => {
      const sel = row.querySelector('select'), ans = row.querySelector('.sq-answer');
      const changedQ = sel.value !== origQ(row);
      const typed = ans.value.trim() !== '' && !isMask(ans);
      const needsAnswer = changedQ || !hadAns(row);

      // Don't allow the same question twice.
      [...sel.options].forEach(o => {
        o.disabled = o.value !== '' && chosen.some((c, j) => j !== i && c === o.value);
      });

      if (sel.value === '' || (needsAnswer && !typed)) valid = false;
      if (changedQ || typed) dirty = true;
    });
    if (saveBtn) saveBtn.disabled = !(dirty && valid);
  }

  rows.forEach(row => {
    const sel = row.querySelector('select'), ans = row.querySelector('.sq-answer');

    // Click into a masked answer: clear it so the user can type a replacement.
    ans.addEventListener('focus', () => { if (isMask(ans)) ans.value = ''; });
    // Left empty and question unchanged: put the mask back (the saved answer is kept).
    ans.addEventListener('blur', () => {
      if (ans.value.trim() === '' && hadAns(row) && sel.value === origQ(row)) ans.value = MASK;
      refresh();
    });
    // Changing the question invalidates the old answer -> empty field, new answer required.
    // Switching back to the original question restores the saved (masked) answer.
    sel.addEventListener('change', () => {
      if (sel.value !== origQ(row)) { if (isMask(ans)) ans.value = ''; }
      else if (hadAns(row) && ans.value.trim() === '') ans.value = MASK;
      refresh();
    });
  });

  // A still-masked field means "keep the saved answer": send it as blank.
  form.addEventListener('submit', () => {
    rows.forEach(r => { const a = r.querySelector('.sq-answer'); if (isMask(a)) a.value = ''; });
  });

  form.addEventListener('input', refresh);
  refresh();
})();
</script>