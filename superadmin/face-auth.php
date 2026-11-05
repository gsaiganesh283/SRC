<?php
require_once 'session.php';
require_once '../config/config.php';
require_once '../includes/logo-helper.php';
require_once '../includes/face-helper.php';

// Ensure face columns exist in database
FaceHelper::ensureColumnsExist($pdo);

$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');
$page_title = 'Face Lock';

$message = '';
$messageType = '';

// Check current face auth status
$isFaceEnabled = FaceHelper::isFaceEnabled($pdo, $superadmin_id);
$currentFaceName = FaceHelper::getFaceName($pdo, $superadmin_id);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'enable' || $action === 're-register') {
        $descriptorJson = $_POST['face_descriptor'] ?? '';
        $faceName = trim($_POST['face_name'] ?? '');
        $descriptor = FaceHelper::validateDescriptor($descriptorJson);
        
        if (empty($faceName)) {
            $message = 'Please enter a name for face registration.';
            $messageType = 'error';
        } elseif ($descriptor === false) {
            $message = 'Invalid face data. Please try capturing your face again.';
            $messageType = 'error';
        } else {
            $result = FaceHelper::enableFaceAuth($pdo, $superadmin_id, $descriptor, $faceName);
            if ($result === true) {
                $isFaceEnabled = true;
                $currentFaceName = $faceName;
                $msg = $action === 'enable' 
                    ? 'Face Lock enabled successfully! You can now login with your face.' 
                    : 'Face data updated successfully!';
                $message = $msg;
                $messageType = 'success';
                
                try {
                    $logAction = $action === 'enable' ? 'Enabled Face Lock' : 'Re-registered Face Lock';
                    $stmt = $pdo->prepare("INSERT INTO audit_logs (UserType, UserID, Username, Action, IPAddress) VALUES ('SuperAdmin', ?, ?, ?, ?)");
                    $stmt->execute([$superadmin_id, $superadmin_username, $logAction . " (Name: $faceName)", $_SERVER['REMOTE_ADDR'] ?? 'Unknown']);
                } catch (Exception $e) { error_log('Audit log failed: ' . $e->getMessage()); }
            } else {
                $message = 'Failed: ' . htmlspecialchars(is_string($result) ? $result : 'Unknown error');
                $messageType = 'error';
            }
        }
    }
    
    if ($action === 'disable') {
        if (FaceHelper::disableFaceAuth($pdo, $superadmin_id)) {
            $isFaceEnabled = false;
            $currentFaceName = null;
            $message = 'Face Lock has been disabled.';
            $messageType = 'success';
            try {
                $stmt = $pdo->prepare("INSERT INTO audit_logs (UserType, UserID, Username, Action, IPAddress) VALUES ('SuperAdmin', ?, ?, 'Disabled Face Lock', ?)");
                $stmt->execute([$superadmin_id, $superadmin_username, $_SERVER['REMOTE_ADDR'] ?? 'Unknown']);
            } catch (Exception $e) { error_log('Audit log failed: ' . $e->getMessage()); }
        } else {
            $message = 'Failed to disable Face Lock. Please try again.';
            $messageType = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Face Lock - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="shared-styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .face-page { max-width: 960px; margin: 0 auto; padding: 20px; }

        /* ---- Alert ---- */
        .fl-alert {
            padding: 16px 20px; border-radius: 12px; margin-bottom: 24px;
            display: flex; align-items: center; gap: 12px; font-size: 14px; line-height: 1.5;
            animation: fadeSlideIn .4s ease;
        }
        .fl-alert i { font-size: 20px; flex-shrink: 0; }
        .fl-alert-success { background: #d1fae5; border: 1px solid #6ee7b7; color: #065f46; }
        .fl-alert-error   { background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; }

        @keyframes fadeSlideIn { from { opacity: 0; transform: translateY(-12px); } to { opacity: 1; transform: translateY(0); } }

        /* ---- Status Banner ---- */
        .status-banner {
            display: flex; align-items: center; gap: 20px;
            background: var(--bg-secondary, #fff); border-radius: 16px; padding: 24px 28px;
            box-shadow: var(--shadow); margin-bottom: 28px; flex-wrap: wrap;
        }
        .status-banner .sb-icon {
            width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-size: 28px; flex-shrink: 0;
        }
        .sb-icon.on  { background: linear-gradient(135deg,#10b981,#059669); color: #fff; }
        .sb-icon.off { background: linear-gradient(135deg,#94a3b8,#64748b); color: #fff; }
        .status-banner .sb-text h3 { font-size: 18px; color: var(--text-primary,#1a1a2e); margin-bottom: 4px; }
        .status-banner .sb-text p  { font-size: 13px; color: var(--text-secondary,#64748b); }
        .sb-tag {
            margin-left: auto; padding: 6px 16px; border-radius: 20px; font-size: 12px; font-weight: 700; letter-spacing: .5px;
        }
        .sb-tag.on  { background: #d1fae5; color: #065f46; }
        .sb-tag.off { background: #f1f5f9; color: #475569; }

        /* ---- Card ---- */
        .fl-card {
            background: var(--bg-secondary, #fff); border-radius: 16px; padding: 32px;
            box-shadow: var(--shadow); margin-bottom: 28px; position: relative; overflow: hidden;
        }
        .fl-card h3 { font-size: 20px; font-weight: 700; color: var(--text-primary,#1a1a2e); margin-bottom: 6px; display: flex; align-items: center; gap: 10px; }
        .fl-card .subtitle { font-size: 14px; color: var(--text-secondary,#666); margin-bottom: 24px; }

        /* ---- Oval Camera ---- */
        .camera-stage {
            width: 300px; height: 380px; margin: 0 auto 24px; position: relative; border-radius: 50%; overflow: hidden;
            background: #0f172a;
        }
        .camera-stage video { width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1); }
        .camera-stage canvas.detect-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; }
        .camera-stage .oval-guide {
            position: absolute; inset: 0; pointer-events: none; z-index: 4;
        }
        .camera-stage .oval-guide svg { width: 100%; height: 100%; }

        /* Pulsing ring animation for scanning state */
        .camera-stage .pulse-ring {
            position: absolute; inset: 8px; border-radius: 50%; border: 3px solid rgba(59,130,246,.5);
            z-index: 5; pointer-events: none; opacity: 0;
        }
        .camera-stage.scanning .pulse-ring {
            animation: pulseRing 1.8s ease-in-out infinite;
        }
        @keyframes pulseRing {
            0%   { opacity: .6; transform: scale(.97); }
            50%  { opacity: 1;  transform: scale(1.02); border-color: rgba(124,58,237,.7); }
            100% { opacity: .6; transform: scale(.97); }
        }
        .camera-stage.captured .pulse-ring {
            animation: none; opacity: 1; border-color: #10b981;
        }
        .camera-stage.failed .pulse-ring {
            animation: none; opacity: 1; border-color: #ef4444;
        }

        /* Camera overlay placeholder */
        .cam-placeholder {
            position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center;
            color: rgba(255,255,255,.7); z-index: 6; background: rgba(15,23,42,.65); gap: 8px; transition: opacity .3s;
        }
        .cam-placeholder.hidden { opacity: 0; pointer-events: none; }
        .cam-placeholder i { font-size: 40px; }
        .cam-placeholder span { font-size: 13px; }

        /* ---- Steps indicator ---- */
        .reg-steps {
            display: flex; align-items: center; justify-content: center; gap: 0; margin-bottom: 28px;
        }
        .reg-steps .step {
            display: flex; align-items: center; gap: 8px; padding: 10px 18px;
            font-size: 13px; font-weight: 600; color: var(--text-secondary,#94a3b8);
            transition: all .3s;
        }
        .reg-steps .step .step-num {
            width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
            background: var(--bg-tertiary,#f1f5f9); font-size: 13px; font-weight: 700; transition: all .3s;
        }
        .reg-steps .step.active { color: #7e22ce; }
        .reg-steps .step.active .step-num { background: linear-gradient(135deg,#7e22ce,#6d28d9); color: #fff; }
        .reg-steps .step.done { color: #10b981; }
        .reg-steps .step.done .step-num { background: #10b981; color: #fff; }
        .reg-steps .step-line { width: 40px; height: 2px; background: var(--border-color,#e2e8f0); transition: background .3s; }
        .reg-steps .step-line.done { background: #10b981; }

        /* ---- Status pill ---- */
        .face-status {
            display: flex; align-items: center; justify-content: center; gap: 10px;
            padding: 12px 20px; border-radius: 50px; font-weight: 600; font-size: 14px;
            margin-bottom: 20px; transition: all .35s ease;
        }
        .face-status.loading  { background: #eff6ff; color: #1e40af; }
        .face-status.scanning { background: #fef3c7; color: #92400e; }
        .face-status.found    { background: #d1fae5; color: #065f46; }
        .face-status.captured { background: #d1fae5; color: #065f46; }
        .face-status.error    { background: #fee2e2; color: #991b1b; }

        /* Progress */
        .fl-progress { background: #e2e8f0; border-radius: 10px; height: 6px; margin-bottom: 20px; overflow: hidden; }
        .fl-progress-bar { height: 100%; border-radius: 10px; background: linear-gradient(90deg,#7e22ce,#3b82f6); transition: width .4s ease; width: 0%; }

        /* ---- Name Input ---- */
        .name-input-group {
            max-width: 360px; margin: 0 auto 24px; text-align: left;
        }
        .name-input-group label {
            display: block; margin-bottom: 6px; font-weight: 600; font-size: 14px; color: var(--text-primary,#333);
        }
        .name-input-group .name-field {
            width: 100%; padding: 14px 16px 14px 44px; border: 2px solid var(--border-color,#e2e8f0);
            border-radius: 12px; font-size: 15px; transition: all .3s;
            background: var(--bg-tertiary,#f8fafc); color: var(--text-primary,#333);
            box-sizing: border-box;
        }
        .name-input-group .name-field:focus { outline: none; border-color: #7e22ce; box-shadow: 0 0 0 4px rgba(126,34,206,.1); }
        .name-input-group .input-wrap { position: relative; }
        .name-input-group .input-wrap i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; }

        /* ---- Buttons ---- */
        .fl-btns { display: flex; gap: 12px; flex-wrap: wrap; justify-content: center; }
        .fl-btn {
            display: inline-flex; align-items: center; gap: 8px; padding: 14px 28px;
            border: none; border-radius: 12px; font-size: 15px; font-weight: 600;
            cursor: pointer; transition: all .25s ease; text-decoration: none;
        }
        .fl-btn:hover:not(:disabled) { transform: translateY(-2px); }
        .fl-btn:disabled { opacity: .55; cursor: not-allowed; transform: none; }
        .fl-btn-primary { background: linear-gradient(135deg,#7e22ce,#6d28d9); color: #fff; box-shadow: 0 4px 14px rgba(126,34,206,.3); }
        .fl-btn-primary:hover:not(:disabled) { box-shadow: 0 8px 24px rgba(126,34,206,.4); }
        .fl-btn-success { background: linear-gradient(135deg,#10b981,#059669); color: #fff; box-shadow: 0 4px 14px rgba(16,185,129,.3); }
        .fl-btn-danger  { background: linear-gradient(135deg,#ef4444,#dc2626); color: #fff; box-shadow: 0 4px 14px rgba(239,68,68,.3); }
        .fl-btn-outline { background: transparent; color: var(--text-primary,#333); border: 2px solid var(--border-color,#e2e8f0); }
        .fl-btn-outline:hover { background: var(--bg-tertiary,#f8f9fa); }
        .fl-btn-ghost { background: none; border: none; color: #7e22ce; font-weight: 600; padding: 10px 16px; }

        /* Spinner */
        .fl-spin { display: inline-block; width: 18px; height: 18px; border: 2.5px solid rgba(255,255,255,.3); border-radius: 50%; border-top-color: #fff; animation: flSpin .7s linear infinite; }
        .fl-spin.dark { border-color: rgba(0,0,0,.1); border-top-color: #7e22ce; }
        @keyframes flSpin { to { transform: rotate(360deg); } }

        /* ---- Security Section ---- */
        .sec-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(240px,1fr)); gap: 16px; }
        .sec-item {
            display: flex; align-items: flex-start; gap: 14px; padding: 16px; border-radius: 12px;
            background: var(--bg-tertiary,#f8fafc); transition: all .3s;
        }
        .sec-item:hover { transform: translateY(-2px); box-shadow: var(--shadow); }
        .sec-item i { color: #10b981; font-size: 18px; margin-top: 2px; flex-shrink: 0; }
        .sec-item strong { display: block; font-size: 13px; color: var(--text-primary,#333); margin-bottom: 2px; }
        .sec-item span  { font-size: 12px; color: var(--text-secondary,#64748b); line-height: 1.5; }

        /* ---- Registered Face Card ---- */
        .face-profile {
            display: flex; align-items: center; gap: 20px; padding: 20px; border-radius: 14px;
            background: linear-gradient(135deg,#f0fdf4,#ecfdf5); border: 1px solid #bbf7d0; margin-bottom: 24px;
        }
        .face-profile .fp-avatar {
            width: 56px; height: 56px; border-radius: 50%; background: linear-gradient(135deg,#10b981,#059669);
            display: flex; align-items: center; justify-content: center; color: #fff; font-size: 24px; flex-shrink: 0;
        }
        .face-profile .fp-info h4 { font-size: 16px; color: #065f46; margin-bottom: 2px; }
        .face-profile .fp-info p  { font-size: 13px; color: #047857; }

        /* Responsive */
        @media (max-width: 600px) {
            .camera-stage { width: 240px; height: 300px; }
            .fl-card { padding: 20px; }
            .status-banner { padding: 16px; gap: 14px; }
            .reg-steps .step { padding: 8px 10px; font-size: 12px; }
            .reg-steps .step-line { width: 20px; }
        }
    </style>
</head>
<body>
    <!-- ===== APP NAVBAR ===== -->
    <?php include 'includes/navbar.php'; ?>

    <!-- ===== QUICK NAV ===== -->
    <div style="max-width:960px;margin:0 auto;padding:20px 20px 0;">
    </div>

    <!-- ===== PAGE CONTENT ===== -->
    <div class="face-page">

        <!-- Alert -->
        <?php if ($message): ?>
        <div class="fl-alert fl-alert-<?php echo $messageType; ?>">
            <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
        <?php endif; ?>

        <!-- Status Banner -->
        <div class="status-banner">
            <div class="sb-icon <?php echo $isFaceEnabled ? 'on' : 'off'; ?>">
                <i class="fas fa-<?php echo $isFaceEnabled ? 'face-smile' : 'face-meh'; ?>"></i>
            </div>
            <div class="sb-text">
                <h3>Face Lock Authentication</h3>
                <p><?php echo $isFaceEnabled
                    ? 'Registered as: <strong>' . htmlspecialchars($currentFaceName ?? $superadmin_username) . '</strong>'
                    : 'Not configured — set up below to login with your face'; ?></p>
            </div>
            <span class="sb-tag <?php echo $isFaceEnabled ? 'on' : 'off'; ?>">
                <?php echo $isFaceEnabled ? 'ENABLED' : 'DISABLED'; ?>
            </span>
        </div>

        <?php if ($isFaceEnabled): ?>
        <!-- ==================== ENABLED STATE ==================== -->
        <div class="fl-card">
            <div class="face-profile">
                <div class="fp-avatar"><i class="fas fa-user-check"></i></div>
                <div class="fp-info">
                    <h4><?php echo htmlspecialchars($currentFaceName ?? $superadmin_username); ?></h4>
                    <p>Face Lock is active — you can login using your face.</p>
                </div>
            </div>

            <div class="fl-btns" style="margin-bottom:20px;">
                <button class="fl-btn fl-btn-primary" onclick="showReRegister()">
                    <i class="fas fa-sync-alt"></i> Re-Register Face
                </button>
                <button class="fl-btn fl-btn-danger" onclick="confirmDisable()">
                    <i class="fas fa-times-circle"></i> Disable Face Lock
                </button>
                <a href="two-factor-auth.php" class="fl-btn fl-btn-outline">
                    <i class="fas fa-shield-alt"></i> Google Authenticator
                </a>
            </div>

            <!-- Re-Register (hidden) -->
            <div id="reRegSection" style="display:none;">
                <hr style="border-color:var(--border-color,#e2e8f0);margin:24px 0;">
                <div id="reRegInner"></div>
            </div>

            <form method="POST" id="disableForm" style="display:none;">
                <input type="hidden" name="action" value="disable">
            </form>
        </div>

        <?php else: ?>
        <!-- ==================== SETUP STATE ==================== -->
        <div class="fl-card" id="setupCard">
            <h3><i class="fas fa-fingerprint"></i> Set Up Face Lock</h3>
            <p class="subtitle">Register your face to unlock super admin login with a glance.</p>

            <!-- Step Indicator -->
            <div class="reg-steps" id="regSteps">
                <div class="step active" id="step1"><span class="step-num">1</span> Enter Name</div>
                <div class="step-line" id="line1"></div>
                <div class="step active" id="step2"><span class="step-num">2</span> Scan Face</div>
                <div class="step-line" id="line2"></div>
                <div class="step" id="step3"><span class="step-num">3</span> Save</div>
            </div>

            <!-- Name Input -->
            <div class="name-input-group" id="nameGroup">
                <label for="faceName">Your Display Name</label>
                <div class="input-wrap">
                    <i class="fas fa-user"></i>
                    <input type="text" id="faceName" class="name-field" placeholder="e.g. <?php echo htmlspecialchars($superadmin_name ?? 'Admin'); ?>" value="<?php echo htmlspecialchars($superadmin_name ?? ''); ?>" maxlength="100">
                </div>
            </div>

            <!-- Model Loading -->
            <div class="face-status loading" id="statusPill">
                <div class="fl-spin dark"></div>
                <span id="statusText">Loading face recognition AI...</span>
            </div>
            <div class="fl-progress" id="progressWrap"><div class="fl-progress-bar" id="progressBar"></div></div>

            <!-- Oval Camera -->
            <div class="camera-stage" id="camStage">
                <div class="pulse-ring"></div>
                <div class="oval-guide">
                    <svg viewBox="0 0 300 380" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <mask id="ovalMask">
                                <rect width="300" height="380" fill="white"/>
                                <ellipse cx="150" cy="185" rx="110" ry="145" fill="black"/>
                            </mask>
                        </defs>
                        <rect width="300" height="380" fill="rgba(0,0,0,0.45)" mask="url(#ovalMask)"/>
                        <ellipse cx="150" cy="185" rx="110" ry="145" stroke="rgba(255,255,255,0.35)" stroke-width="2.5" fill="none" stroke-dasharray="8 4"/>
                    </svg>
                </div>
                <div class="cam-placeholder" id="camPlaceholder">
                    <i class="fas fa-camera"></i>
                    <span>Click Start Camera</span>
                </div>
                <video id="camVideo" autoplay muted playsinline></video>
                <canvas id="camCanvas" class="detect-overlay"></canvas>
            </div>

            <!-- Buttons -->
            <div class="fl-btns" id="actionBtns">
                <button class="fl-btn fl-btn-primary" id="btnStart" onclick="startSetup()">
                    <i class="fas fa-video"></i> Start Camera
                </button>
                <button class="fl-btn fl-btn-success" id="btnCapture" onclick="captureSetup()" disabled style="display:none;">
                    <i class="fas fa-camera"></i> Capture Face
                </button>
                <button class="fl-btn fl-btn-success" id="btnSave" onclick="saveSetup('enable')" style="display:none;">
                    <i class="fas fa-save"></i> Enable Face Lock
                </button>
                <button class="fl-btn fl-btn-outline" id="btnRetake" onclick="retakeSetup()" style="display:none;">
                    <i class="fas fa-redo"></i> Retake
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- Security Info -->
        <div class="fl-card">
            <h3><i class="fas fa-lock"></i> Security & Privacy</h3>
            <p class="subtitle">How we protect your data</p>
            <div class="sec-grid">
                <div class="sec-item">
                    <i class="fas fa-eye-slash"></i>
                    <div><strong>No photos stored</strong><span>Only a 128-number math descriptor — your face can't be reconstructed.</span></div>
                </div>
                <div class="sec-item">
                    <i class="fas fa-laptop-code"></i>
                    <div><strong>Browser-side AI</strong><span>All face processing runs in your browser. Camera feed never leaves your device.</span></div>
                </div>
                <div class="sec-item">
                    <i class="fas fa-ruler-combined"></i>
                    <div><strong>Strict matching</strong><span>Euclidean distance with tight threshold ensures high-accuracy verification.</span></div>
                </div>
                <div class="sec-item">
                    <i class="fas fa-clipboard-list"></i>
                    <div><strong>Audit logged</strong><span>All face lock actions are recorded in the system audit trail.</span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== APP FOOTER ===== -->
    <footer style="text-align:center;padding:24px 20px 32px;font-size:13px;color:var(--text-secondary,#94a3b8);">
        <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($siteName); ?> — Super Admin Portal</p>
    </footer>

    <!-- face-api.js -->
    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
    <!-- Theme -->
    <script src="theme-manager.js"></script>

    <script>
    // ===================================================
    //  FACE LOCK REGISTRATION — Advanced Client Logic
    // ===================================================
    const MODEL_URL = 'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights';
    let modelsLoaded = false, stream = null, detLoop = null, descriptor = null;

    // ---- Load models ----
    async function loadModels() {
        if (modelsLoaded) return true;
        const bar = document.getElementById('progressBar');
        const pill = document.getElementById('statusPill');
        const txt = document.getElementById('statusText');
        if (pill) { pill.className = 'face-status loading'; pill.style.display = 'flex'; }
        if (txt) txt.textContent = 'Loading face recognition AI...';
        try {
            if (bar) bar.style.width = '10%';
            await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL);
            if (bar) bar.style.width = '40%';
            await faceapi.nets.faceLandmark68TinyNet.loadFromUri(MODEL_URL);
            if (bar) bar.style.width = '70%';
            await faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL);
            if (bar) bar.style.width = '100%';
            modelsLoaded = true;
            setTimeout(() => { const pw = document.getElementById('progressWrap'); if (pw) pw.style.display = 'none'; }, 500);
            return true;
        } catch (e) {
            console.error('Model load error:', e);
            if (pill) pill.className = 'face-status error';
            if (txt) txt.textContent = 'Failed to load AI models. Check your internet connection.';
            return false;
        }
    }

    // ---- Start camera (registration) ----
    async function startSetup() {
        const btn = document.getElementById('btnStart');
        if (btn) { btn.disabled = true; btn.innerHTML = '<div class="fl-spin"></div> Loading...'; }

        if (!await loadModels()) { if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-video"></i> Retry'; } return; }

        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { width:{ideal:480}, height:{ideal:640}, facingMode:'user' } });
            const vid = document.getElementById('camVideo');
            vid.srcObject = stream;
            await new Promise(r => { vid.onloadedmetadata = () => { vid.play(); r(); }; });

            document.getElementById('camPlaceholder').classList.add('hidden');
            document.getElementById('camStage').classList.add('scanning');
            if (btn) btn.style.display = 'none';
            const cap = document.getElementById('btnCapture');
            if (cap) { cap.style.display = 'inline-flex'; cap.disabled = false; }

            runDetection();
        } catch (err) {
            console.error('Camera error:', err);
            const pill = document.getElementById('statusPill');
            const txt = document.getElementById('statusText');
            if (pill) pill.className = 'face-status error';
            if (txt) txt.textContent = 'Camera access denied. Please allow camera.';
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-video"></i> Retry'; }
        }
    }

    // ---- Detection loop (no landmark drawing — clean oval) ----
    function runDetection() {
        const vid = document.getElementById('camVideo');
        const pill = document.getElementById('statusPill');
        const txt = document.getElementById('statusText');

        if (pill) { pill.className = 'face-status scanning'; pill.style.display = 'flex'; }
        if (txt) txt.textContent = 'Position your face inside the oval...';

        detLoop = setInterval(async () => {
            try {
                const det = await faceapi.detectAllFaces(vid, new faceapi.TinyFaceDetectorOptions({ inputSize: 224, scoreThreshold: 0.5 }));

                if (det.length === 1) {
                    if (pill) pill.className = 'face-status found';
                    if (txt) txt.textContent = 'Face detected — click Capture when ready';
                } else if (det.length === 0) {
                    if (pill) pill.className = 'face-status scanning';
                    if (txt) txt.textContent = 'Position your face inside the oval...';
                } else {
                    if (pill) pill.className = 'face-status error';
                    if (txt) txt.textContent = 'Multiple faces detected — show only your face';
                }
            } catch (e) { /* ignore */ }
        }, 250);
    }

    // ---- Capture face ----
    async function captureSetup() {
        const cap = document.getElementById('btnCapture');
        const pill = document.getElementById('statusPill');
        const txt = document.getElementById('statusText');
        if (cap) { cap.disabled = true; cap.innerHTML = '<div class="fl-spin"></div> Analyzing...'; }
        if (pill) pill.className = 'face-status loading';
        if (txt) txt.textContent = 'Extracting face data — hold still...';

        // IMPORTANT: Stop detection loop first to free WebGL resources
        if (detLoop) { clearInterval(detLoop); detLoop = null; }
        // Small delay to let any pending detection finish
        await new Promise(r => setTimeout(r, 300));

        try {
            const vid = document.getElementById('camVideo');
            let det = null;
            // Try multiple input sizes (smaller = more lenient)
            const sizes = [320, 256, 224, 416];
            for (const size of sizes) {
                try {
                    det = await faceapi.detectSingleFace(vid, new faceapi.TinyFaceDetectorOptions({ inputSize: size, scoreThreshold: 0.4 }))
                        .withFaceLandmarks(true).withFaceDescriptor();
                    if (det) break;
                } catch (e) { console.warn('Size ' + size + ' failed:', e); }
            }

            if (!det) {
                if (pill) pill.className = 'face-status error';
                if (txt) txt.textContent = 'No face found. Face the camera directly and try again.';
                if (cap) { cap.disabled = false; cap.innerHTML = '<i class="fas fa-camera"></i> Capture Face'; }
                // Restart detection loop
                runDetection();
                return;
            }

            descriptor = Array.from(det.descriptor);
            stopStream();

            document.getElementById('camStage').classList.remove('scanning');
            document.getElementById('camStage').classList.add('captured');
            if (pill) pill.className = 'face-status captured';
            if (txt) txt.textContent = 'Face captured successfully!';

            // Update steps
            markStep(2, 'done'); markLine(1, true); markStep(3, 'active');

            if (cap) cap.style.display = 'none';
            const save = document.getElementById('btnSave');
            const retake = document.getElementById('btnRetake');
            if (save) save.style.display = 'inline-flex';
            if (retake) retake.style.display = 'inline-flex';

        } catch (err) {
            console.error('Capture error:', err);
            if (pill) pill.className = 'face-status error';
            if (txt) txt.textContent = 'Capture failed. Try again.';
            if (cap) { cap.disabled = false; cap.innerHTML = '<i class="fas fa-camera"></i> Capture Face'; }
        }
    }

    // ---- Save ----
    function saveSetup(action) {
        const nameEl = document.getElementById('faceName') || document.getElementById('reRegName');
        const name = nameEl ? nameEl.value.trim() : '';
        if (!name) { alert('Please enter a display name.'); if (nameEl) nameEl.focus(); return; }
        if (!descriptor || descriptor.length !== 128) { alert('No valid face data. Please retake.'); return; }

        const form = document.createElement('form');
        form.method = 'POST';
        const fields = { action: action, face_descriptor: JSON.stringify(descriptor), face_name: name };
        for (const [k,v] of Object.entries(fields)) {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = k; inp.value = v;
            form.appendChild(inp);
        }
        document.body.appendChild(form);
        form.submit();
    }

    // ---- Retake ----
    function retakeSetup() {
        descriptor = null;
        const stage = document.getElementById('camStage');
        stage.classList.remove('captured','failed');
        const ph = document.getElementById('camPlaceholder');
        if (ph) { ph.classList.remove('hidden'); }

        document.getElementById('btnSave').style.display = 'none';
        document.getElementById('btnRetake').style.display = 'none';
        document.getElementById('btnStart').style.display = 'inline-flex';
        document.getElementById('btnStart').disabled = false;
        document.getElementById('btnStart').innerHTML = '<i class="fas fa-video"></i> Start Camera';

        markStep(2, 'active'); markStep(3, ''); markLine(1, false);
        startSetup();
    }

    // ---- Steps helpers ----
    function markStep(n, state) {
        const el = document.getElementById('step' + n);
        if (!el) return;
        el.className = 'step' + (state ? ' ' + state : '');
    }
    function markLine(n, done) {
        const el = document.getElementById('line' + n);
        if (!el) return;
        el.className = 'step-line' + (done ? ' done' : '');
    }

    // ---- Stream cleanup ----
    function stopStream() {
        if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
    }

    // ---- Re-register (enabled state) ----
    function showReRegister() {
        const sec = document.getElementById('reRegSection');
        sec.style.display = 'block';
        const inner = document.getElementById('reRegInner');
        inner.innerHTML = `
            <h3 style="display:flex;align-items:center;gap:10px;font-size:20px;color:var(--text-primary,#1a1a2e);margin-bottom:6px;">
                <i class="fas fa-sync-alt"></i> Re-Register Face
            </h3>
            <p class="subtitle" style="font-size:14px;color:var(--text-secondary);margin-bottom:20px;">Capture a new face to update your registration.</p>

            <div class="name-input-group">
                <label for="reRegName">Display Name</label>
                <div class="input-wrap">
                    <i class="fas fa-user"></i>
                    <input type="text" id="reRegName" class="name-field" value="${document.querySelector('.face-profile .fp-info h4')?.textContent || ''}" maxlength="100">
                </div>
            </div>

            <div class="face-status loading" id="statusPill" style="display:flex;">
                <div class="fl-spin dark"></div>
                <span id="statusText">Loading face recognition AI...</span>
            </div>
            <div class="fl-progress" id="progressWrap"><div class="fl-progress-bar" id="progressBar"></div></div>

            <div class="camera-stage" id="camStage">
                <div class="pulse-ring"></div>
                <div class="oval-guide">
                    <svg viewBox="0 0 300 380" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <defs><mask id="ovalMask2"><rect width="300" height="380" fill="white"/><ellipse cx="150" cy="185" rx="110" ry="145" fill="black"/></mask></defs>
                        <rect width="300" height="380" fill="rgba(0,0,0,0.45)" mask="url(#ovalMask2)"/>
                        <ellipse cx="150" cy="185" rx="110" ry="145" stroke="rgba(255,255,255,0.35)" stroke-width="2.5" fill="none" stroke-dasharray="8 4"/>
                    </svg>
                </div>
                <div class="cam-placeholder" id="camPlaceholder"><i class="fas fa-camera"></i><span>Click Start Camera</span></div>
                <video id="camVideo" autoplay muted playsinline></video>
                <canvas id="camCanvas" class="detect-overlay"></canvas>
            </div>

            <div class="fl-btns">
                <button class="fl-btn fl-btn-primary" id="btnStart" onclick="startSetup()"><i class="fas fa-video"></i> Start Camera</button>
                <button class="fl-btn fl-btn-success" id="btnCapture" onclick="captureSetup()" disabled style="display:none;"><i class="fas fa-camera"></i> Capture Face</button>
                <button class="fl-btn fl-btn-success" id="btnSave" onclick="saveSetup('re-register')" style="display:none;"><i class="fas fa-save"></i> Update Face Data</button>
                <button class="fl-btn fl-btn-outline" id="btnRetake" onclick="retakeSetup()" style="display:none;"><i class="fas fa-redo"></i> Retake</button>
            </div>
        `;
        sec.scrollIntoView({ behavior: 'smooth' });
    }

    function confirmDisable() {
        if (confirm('Disable Face Lock? You will need to set it up again.')) {
            document.getElementById('disableForm').submit();
        }
    }

    window.addEventListener('beforeunload', () => { stopStream(); if (detLoop) clearInterval(detLoop); });
    </script>
</body>
</html>