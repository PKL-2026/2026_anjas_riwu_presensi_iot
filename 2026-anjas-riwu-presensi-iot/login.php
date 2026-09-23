<?php
date_default_timezone_set('Asia/Jakarta');
session_start();
require_once 'koneksi.php';

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($password)) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && $password === $user['password']) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_user'] = $user['username'];
            $_SESSION['admin_id'] = $user['id'];
            header('Location: index.php');
            exit;
        } else {
            $error = 'Username atau password salah!';
        }
    } else {
        $error = 'Harap isi semua kolom!';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Login — Sistem Presensi IoT</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    
    body {
      font-family: 'Inter', sans-serif;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #050a15;
      overflow: hidden;
      position: relative;
    }

    /* ===== ANIMATED BACKGROUND ===== */
    body::before {
      content: '';
      position: absolute;
      top: -50%;
      left: -50%;
      width: 200%;
      height: 200%;
      background: 
        radial-gradient(ellipse at 20% 50%, rgba(34, 197, 94, 0.08) 0%, transparent 50%),
        radial-gradient(ellipse at 80% 20%, rgba(16, 185, 129, 0.06) 0%, transparent 50%),
        radial-gradient(ellipse at 50% 80%, rgba(5, 150, 105, 0.05) 0%, transparent 50%);
      animation: bgFloat 20s ease-in-out infinite;
    }
    @keyframes bgFloat {
      0%, 100% { transform: translate(0, 0) rotate(0deg); }
      33% { transform: translate(30px, -20px) rotate(1deg); }
      66% { transform: translate(-20px, 15px) rotate(-1deg); }
    }

    /* ===== FLOATING PARTICLES ===== */
    .particles {
      position: fixed;
      inset: 0;
      pointer-events: none;
      overflow: hidden;
    }
    .particle {
      position: absolute;
      width: 4px;
      height: 4px;
      background: rgba(34, 197, 94, 0.3);
      border-radius: 50%;
      animation: particleFloat linear infinite;
    }
    @keyframes particleFloat {
      0% { transform: translateY(100vh) scale(0); opacity: 0; }
      10% { opacity: 1; }
      90% { opacity: 1; }
      100% { transform: translateY(-10vh) scale(1); opacity: 0; }
    }

    /* ===== LOGIN CARD ===== */
    .login-container {
      position: relative;
      z-index: 10;
      width: 100%;
      max-width: 420px;
      padding: 1rem;
    }

    .login-card {
      background: rgba(17, 24, 39, 0.85);
      backdrop-filter: blur(24px);
      border: 1px solid rgba(34, 197, 94, 0.15);
      border-radius: 24px;
      padding: 2.5rem 2rem;
      box-shadow: 
        0 0 60px rgba(34, 197, 94, 0.08),
        0 25px 50px rgba(0, 0, 0, 0.4);
      animation: cardSlideIn 0.8s cubic-bezier(0.16, 1, 0.3, 1);
      position: relative;
      overflow: hidden;
    }
    .login-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, transparent, #22c55e, #10b981, transparent);
      animation: shimmer 3s ease-in-out infinite;
    }
    @keyframes shimmer {
      0%, 100% { opacity: 0.5; }
      50% { opacity: 1; }
    }
    @keyframes cardSlideIn {
      from { opacity: 0; transform: translateY(40px) scale(0.95); }
      to { opacity: 1; transform: translateY(0) scale(1); }
    }

    /* ===== LOGO ===== */
    .login-logo {
      text-align: center;
      margin-bottom: 2rem;
    }
    .logo-circle {
      width: 64px;
      height: 64px;
      background: linear-gradient(135deg, #22c55e, #059669);
      border-radius: 18px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 1.8rem;
      margin-bottom: 1rem;
      box-shadow: 0 8px 24px rgba(34, 197, 94, 0.3);
      animation: logoPulse 3s ease-in-out infinite;
    }
    @keyframes logoPulse {
      0%, 100% { box-shadow: 0 8px 24px rgba(34, 197, 94, 0.3); }
      50% { box-shadow: 0 8px 40px rgba(34, 197, 94, 0.5); }
    }
    .login-logo h1 {
      font-size: 1.4rem;
      font-weight: 700;
      color: #f0fdf4;
      letter-spacing: -0.02em;
    }
    .login-logo p {
      font-size: 0.8rem;
      color: #64748b;
      margin-top: 0.3rem;
      font-weight: 400;
    }

    /* ===== ERROR ===== */
    .error-msg {
      background: rgba(239, 68, 68, 0.1);
      border: 1px solid rgba(239, 68, 68, 0.3);
      color: #f87171;
      padding: 0.7rem 1rem;
      border-radius: 10px;
      font-size: 0.82rem;
      margin-bottom: 1.2rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
      animation: shakeError 0.5s ease;
    }
    @keyframes shakeError {
      0%, 100% { transform: translateX(0); }
      20% { transform: translateX(-8px); }
      40% { transform: translateX(8px); }
      60% { transform: translateX(-4px); }
      80% { transform: translateX(4px); }
    }

    /* ===== FORM FIELDS ===== */
    .form-field {
      position: relative;
      margin-bottom: 1.5rem;
    }
    .form-field input {
      width: 100%;
      padding: 1rem 1rem 0.7rem 1rem;
      background: rgba(15, 23, 42, 0.6);
      border: 1.5px solid rgba(255, 255, 255, 0.08);
      border-radius: 12px;
      color: #e2e8f0;
      font-size: 0.95rem;
      font-family: 'Inter', sans-serif;
      transition: all 0.3s ease;
      outline: none;
    }
    .form-field input:focus {
      border-color: #22c55e;
      background: rgba(15, 23, 42, 0.8);
      box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.1);
    }
    .form-field input#password {
      padding-right: 3rem;
    }
    .toggle-password {
      position: absolute;
      right: 1rem;
      top: 50%;
      transform: translateY(-50%);
      cursor: pointer;
      font-size: 1.1rem;
      opacity: 0.5;
      transition: opacity 0.2s ease;
      user-select: none;
      z-index: 2;
    }
    .toggle-password:hover {
      opacity: 1;
    }
    .form-field label {
      position: absolute;
      left: 1rem;
      top: 50%;
      transform: translateY(-50%);
      font-size: 0.9rem;
      color: #64748b;
      pointer-events: none;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      background: transparent;
      padding: 0 4px;
    }
    .form-field input:focus + label,
    .form-field input:not(:placeholder-shown) + label {
      top: 0;
      font-size: 0.7rem;
      color: #22c55e;
      font-weight: 600;
      background: #111827;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }

    /* ===== BUTTON ===== */
    .btn-login {
      width: 100%;
      padding: 0.9rem;
      background: linear-gradient(135deg, #22c55e, #16a34a);
      color: white;
      border: none;
      border-radius: 12px;
      font-size: 0.95rem;
      font-weight: 700;
      font-family: 'Inter', sans-serif;
      cursor: pointer;
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
      letter-spacing: 0.02em;
    }
    .btn-login::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent);
      transition: left 0.5s ease;
    }
    .btn-login:hover {
      box-shadow: 0 8px 24px rgba(34, 197, 94, 0.4);
      transform: translateY(-2px);
    }
    .btn-login:hover::before {
      left: 100%;
    }
    .btn-login:active {
      transform: translateY(0);
      box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3);
    }

    /* ===== FOOTER ===== */
    .login-footer {
      text-align: center;
      margin-top: 1.5rem;
      font-size: 0.72rem;
      color: #475569;
    }
    .login-footer span {
      color: #22c55e;
      font-weight: 600;
    }

    /* ===== RESPONSIVE ===== */
    @media (max-width: 480px) {
      .login-card { padding: 2rem 1.5rem; border-radius: 20px; }
      .login-logo h1 { font-size: 1.2rem; }
    }
  </style>
</head>
<body>
  <!-- Floating Particles -->
  <div class="particles" id="particles"></div>

  <div class="login-container">
    <div class="login-card">
      <div class="login-logo">
        <div class="logo-circle">🔒</div>
        <h1>Sistem Presensi IoT</h1>
        <p>Login untuk mengakses dashboard admin</p>
      </div>

      <?php if ($error): ?>
        <div class="error-msg">⚠️ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" autocomplete="off">
        <div class="form-field">
          <input type="text" name="username" id="username" placeholder=" " required>
          <label for="username">Username</label>
        </div>
        <div class="form-field">
          <input type="password" name="password" id="password" placeholder=" " required>
          <label for="password">Password</label>
          <span class="toggle-password" onclick="togglePass()" title="Intip Password">👁️</span>
        </div>
        <button type="submit" class="btn-login">Masuk ke Dashboard</button>
      </form>

      <div class="login-footer">
        Fingerprint Attendance System — <span>IoT Project</span>
      </div>
    </div>
  </div>

  <script>
    // Generate floating particles
    const container = document.getElementById('particles');
    for (let i = 0; i < 30; i++) {
      const p = document.createElement('div');
      p.className = 'particle';
      p.style.left = Math.random() * 100 + '%';
      p.style.animationDuration = (8 + Math.random() * 12) + 's';
      p.style.animationDelay = Math.random() * 8 + 's';
      p.style.width = p.style.height = (2 + Math.random() * 4) + 'px';
      p.style.opacity = 0.1 + Math.random() * 0.3;
      container.appendChild(p);
    }

    function togglePass() {
      const p = document.getElementById('password');
      if (p.type === 'password') {
        p.type = 'text';
      } else {
        p.type = 'password';
      }
    }
  </script>
</body>
</html>
