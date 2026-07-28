<?php
/**
 * Slate — customer registration.
 */
require_once dirname(__DIR__) . '/config.php';

if (Auth::customer()) {
    header('Location: ' . SLATE_URL . '/customer/');
    exit;
}

$flash = null;
$email = '';
$name  = '';
$phone = '';
$registered = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed. Please try again.'];
    } else {
        $email    = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $name     = trim((string)($_POST['name']  ?? ''));
        $phone    = trim((string)($_POST['phone'] ?? ''));

        $result = Auth::registerCustomer($email, $password, $name, $phone);
        if ($result['ok']) {
            $registered = true;
        } else {
            $flash = ['type' => 'error', 'msg' => $result['error']];
        }
    }
}

$pageTitle = 'Create your account';
$customerPageVariant = 'auth';
require __DIR__ . '/partials/header.php';
?>

<div class="auth-card">
    <?php if ($registered): ?>
        <h1>Check your inbox</h1>
        <p class="auth-card-sub">We've sent a verification link to <strong><?= e($email) ?></strong>.</p>
        <div class="alert alert-success" role="status">
            Click the link in that email to confirm your address, then sign in.
        </div>
        <p class="text-sm text-muted">
            Didn't get it? Check your spam folder, or
            <a href="<?= e(SLATE_URL) ?>/customer/register.php">try again</a>.
        </p>
    <?php else: ?>
        <h1>Create your account</h1>
        <p class="auth-card-sub">A few details and you're in.</p>

        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="on">
            <?= csrf_field() ?>

            <div class="field">
                <label class="field-label" for="name">Your name</label>
                <input type="text" id="name" name="name" required autocomplete="name"
                       value="<?= e($name) ?>" autofocus maxlength="120">
            </div>

            <div class="field">
                <label class="field-label" for="email">Email</label>
                <input type="email" id="email" name="email" required autocomplete="email"
                       value="<?= e($email) ?>" maxlength="190">
            </div>

            <div class="field">
                <label class="field-label" for="phone">Phone <span class="text-muted text-xs">(optional)</span></label>
                <input type="tel" id="phone" name="phone" autocomplete="tel"
                       value="<?= e($phone) ?>" maxlength="40">
            </div>

            <div class="field">
                <label class="field-label" for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="new-password"
                       minlength="8">
                <div class="field-hint">At least 8 characters.</div>
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-lg">Create account</button>
        </form>
    <?php endif; ?>
</div>

<div class="auth-footer">
    Already have an account? <a href="<?= e(SLATE_URL) ?>/customer/login.php">Sign in</a>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
