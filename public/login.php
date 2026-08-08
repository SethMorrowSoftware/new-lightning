<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use StormWatch\App;
use StormWatch\Auth;
use StormWatch\Http;
use StormWatch\View;

App::boot('public');

if (Auth::check()) {
    Http::redirect('index.php');
}

$error = null;
$username = '';
$next = (string) ($_GET['next'] ?? $_POST['next'] ?? '');

if (Http::isPost()) {
    if (!Http::checkCsrf()) {
        $error = 'Your session expired. Try again.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $result = Auth::attemptLogin($username, (string) ($_POST['password'] ?? ''));
        if ($result['ok']) {
            // Only follow a same-site path, never an absolute URL from the query string.
            $target = 'index.php';
            if ($next !== '' && preg_match('#^/[A-Za-z0-9_./?=&-]*$#', $next) === 1 && strpos($next, '//') !== 0) {
                $target = $next;
            }
            Http::redirect($target);
        }
        $error = $result['error'] ?? 'Sign-in failed.';
    }
}

View::start(['title' => 'Sign in', 'bodyClass' => 'centered', 'wrap' => 'wrap tight']);
?>
<div class="card">
  <div class="brand">
    <div class="crest">
      <svg viewBox="0 0 24 24" fill="none" stroke="var(--amber)" stroke-width="1.6"><path d="M4 21V9l3-3 3 3V6l2-2 2 2v3l3-3 3 3v12H4z"/><path d="M9 21v-5h2v5M13 21v-5h2v5"/></svg>
    </div>
    <div>
      <h1>Storm Watch</h1>
      <div class="addr"><?= Http::e(\StormWatch\Settings::getString('venue_name')) ?></div>
    </div>
  </div>

  <?php if ($error !== null): ?>
    <div class="notice err"><?= Http::e($error) ?></div>
  <?php endif; ?>

  <form method="post" autocomplete="on">
    <?= Http::csrfField() ?>
    <input type="hidden" name="next" value="<?= Http::e($next) ?>">
    <div class="field">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" value="<?= Http::e($username) ?>" autocomplete="username" autofocus required>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="current-password" required>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn primary">Sign in</button>
    </div>
  </form>
</div>
<?php
View::end([], 'Storm Watch v' . SW_VERSION);
