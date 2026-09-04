<?php

require_once __DIR__ . '/auth.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } else {
        try {
            $stmt = db()->prepare(
                'SELECT id, username, password_hash, is_admin, is_active
                 FROM users
                 WHERE username = :username
                 LIMIT 1'
            );

            $stmt->execute([
                'username' => $username,
            ]);

            $user = $stmt->fetch();

            if (
                $user &&
                (int)$user['is_active'] === 1 &&
                password_verify($password, $user['password_hash'])
            ) {
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['is_admin'] = (int)$user['is_admin'];

                header('Location: index.php');
                exit;
            }

            $error = 'Invalid username or password.';
        } catch (Throwable $e) {
            $error = 'Unable to connect to the authentication service.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - HVAC Monitoring</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #0f172a;
            color: #e5e7eb;
            font-family: Arial, Helvetica, sans-serif;
        }

        .login-box {
            width: min(400px, calc(100% - 32px));
            padding: 32px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.35);
        }

        .title {
            margin: 0;
            text-align: center;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 1px;
        }

        .subtitle {
            margin: 8px 0 28px;
            text-align: center;
            color: #94a3b8;
            font-size: 14px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-size: 14px;
            color: #cbd5e1;
        }

        input {
            width: 100%;
            padding: 12px 13px;
            margin-bottom: 18px;
            border: 1px solid #475569;
            border-radius: 7px;
            background: #0f172a;
            color: #f8fafc;
            font-size: 15px;
            outline: none;
        }

        input:focus {
            border-color: #60a5fa;
        }

        button {
            width: 100%;
            padding: 12px;
            border: 0;
            border-radius: 7px;
            background: #2563eb;
            color: white;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
        }

        button:hover {
            background: #1d4ed8;
        }

        .error {
            margin-bottom: 18px;
            padding: 10px 12px;
            border-radius: 6px;
            background: #7f1d1d;
            border: 1px solid #991b1b;
            color: #fecaca;
            font-size: 14px;
        }

        .footer {
            margin-top: 22px;
            text-align: center;
            color: #64748b;
            font-size: 12px;
        }
    </style>
</head>

<body>

<div class="login-box">
    <h1 class="title">HVAC MONITORING</h1>
    <div class="subtitle">Secure Login</div>

    <?php if ($error !== ''): ?>
        <div class="error">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <label for="username">Username</label>
        <input
            type="text"
            id="username"
            name="username"
            autocomplete="username"
            required
            autofocus
        >

        <label for="password">Password</label>
        <input
            type="password"
            id="password"
            name="password"
            autocomplete="current-password"
            required
        >

        <button type="submit">LOGIN</button>
    </form>

    <div class="footer">
        Building HVAC Status
    </div>
</div>

</body>
</html>
