<?php
require_once 'config/config.php';
require_once 'includes/logo-helper.php';

$websiteLogoHtml = renderWebsiteLogo('', '', 60);
$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($siteName); ?> - Advanced Chitti Management System</title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="assets/css/styles.css">
    <style> 
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        .navbar-logo-img {
            height: 45px;
            width: auto;
            margin-right: 10px;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }
        
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            opacity: 0.3;
            pointer-events: none;
        }
        
        .navbar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 15px 0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            animation: slideDown 0.5s ease;
        }
        
        @keyframes slideDown {
            from { transform: translateY(-100%); }
            to { transform: translateY(0); }
        }
        
        .navbar .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .navbar .logo {
            font-size: 24px;
            font-weight: bold;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .navbar .nav-links {
            display: flex;
            gap: 30px;
            list-style: none;
        }
        
        .navbar .nav-links a {
            color: #333;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            padding: 8px 16px;
            border-radius: 8px;
        }
        
        .navbar .nav-links a:hover {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            transform: translateY(-2px);
        }
        
        .container {
            max-width: 1400px;
            margin: 100px auto 40px;
            padding: 0 20px;
            position: relative;
            z-index: 1;
        }
        
        .hero-section {
            text-align: center;
            margin-bottom: 60px;
            animation: fadeInUp 0.8s ease;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .hero-icon {
            font-size: 80px;
            margin-bottom: 20px;
            animation: bounce 2s infinite;
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-20px); }
        }
        
        .hero-section h1 {
            color: white;
            font-size: 48px;
            font-weight: 800;
            margin-bottom: 15px;
            text-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
        }
        
        .subtitle {
            color: rgba(255, 255, 255, 0.9);
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        .tagline {
            color: rgba(255, 255, 255, 0.95);
            font-size: 18px;
            font-weight: 500;
            letter-spacing: 2px;
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 30px;
            margin-bottom: 60px;
        }
        
        .feature-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 40px 30px;
            text-align: center;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            transition: all 0.4s ease;
            position: relative;
            overflow: hidden;
            animation: fadeInUp 0.8s ease;
            animation-delay: calc(var(--delay) * 0.1s);
        }
        
        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
        }
        
        .feature-icon {
            font-size: 60px;
            margin-bottom: 20px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .feature-card h3 {
            color: #1e293b;
            font-size: 24px;
            margin-bottom: 15px;
            font-weight: 700;
        }
        
        .feature-card p {
            color: #64748b;
            margin-bottom: 25px;
            line-height: 1.6;
        }
        
        .feature-list {
            list-style: none;
            text-align: left;
            margin: 20px 0;
            padding: 0;
        }
        
        .feature-list li {
            padding: 8px 0;
            color: #475569;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .feature-list li::before {
            content: '✓';
            color: #10b981;
            font-weight: bold;
            font-size: 18px;
        }
        
        .btn-feature {
            display: inline-block;
            padding: 14px 35px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-feature:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.6);
        }
        
        .btn-feature.admin {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.4);
        }
        
        .btn-feature.admin:hover {
            box-shadow: 0 8px 25px rgba(245, 158, 11, 0.6);
        }
        
        .btn-feature.superadmin {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4);
        }
        
        .btn-feature.superadmin:hover {
            box-shadow: 0 8px 25px rgba(239, 68, 68, 0.6);
        }
        
        .stats-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 60px;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            padding: 30px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            transform: scale(1.05);
        }
        
        .stat-card .stat-icon {
            font-size: 40px;
            margin-bottom: 15px;
        }
        
        .stat-card .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 10px;
        }
        
        .stat-card .stat-label {
            color: #64748b;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="particles"></div>
    
    <nav class="navbar">
        <div class="container">
            <div class="logo">
                <?php if (hasLogo('website')): ?>
                    <img src="<?php echo htmlspecialchars(getWebsiteLogo()); ?>" alt="<?php echo htmlspecialchars($siteName); ?>" class="navbar-logo-img">
                <?php else: ?>
                    🏘️
                <?php endif; ?>
                <?php echo htmlspecialchars($siteName); ?>
            </div>
            <ul class="nav-links">
                <li><a href="#home">Home</a></li>
                <li><a href="#features">Features</a></li>
                <li><a href="#portals">Portals</a></li>
                <li><a href="#contact">Contact</a></li>
            </ul>
        </div>
    </nav>
    
    <div class="container">
        <div class="hero-section" id="home">
            <?php if (hasLogo('website')): ?>
                <div class="hero-icon" style="animation: none;"><img src="<?php echo htmlspecialchars(getWebsiteLogo()); ?>" alt="<?php echo htmlspecialchars($siteName); ?>" style="max-height: 100px; width: auto;"></div>
            <?php else: ?>
                <div class="hero-icon">🏘️</div>
            <?php endif; ?>
            <h1><?php echo htmlspecialchars($siteName); ?> Chitti Management System</h1>
            <p class="subtitle">Yerraguntlepalli, Pileru</p>
            <p class="tagline">Transparent • Organized • Efficient • Secure</p>
        </div>

        <div class="stats-section">
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-number">15+</div>
                <div class="stat-label">Active Members</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">💰</div>
                <div class="stat-number">100%</div>
                <div class="stat-label">Transparency</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📊</div>
                <div class="stat-number">Real-time</div>
                <div class="stat-label">Updates</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🔒</div>
                <div class="stat-number">Secure</div>
                <div class="stat-label">Platform</div>
            </div>
        </div>

        <div class="features-grid" id="portals">
            <div class="feature-card" style="--delay: 0;">
                <div class="feature-icon">👥</div>
                <h3>Member Portal</h3>
                <p>Access your complete chitti information</p>
                <ul class="feature-list">
                    <li>View payment history</li>
                    <li>Monthly status updates</li>
                    <li>Interest calculations</li>
                    <li>Transaction records</li>
                    <li>Profile management</li>
                    <li>Download statements</li>
                </ul>
                <a href="auth/member-login.php" target = _blank class="btn-feature">Member Login</a>
            </div>

            <div class="feature-card" style="--delay: 1;">
                <div class="feature-icon">🔐</div>
                <h3>Admin Panel</h3>
                <p>Comprehensive management tools</p>
                <ul class="feature-list">
                    <li>Member management</li>
                    <li>Payment tracking</li>
                    <li>Transaction verification</li>
                    <li>Generate reports</li>
                    <li>Year management</li>
                    <li>Bulk operations</li>
                </ul>
                <a href="auth/admin-login.php" target = _blank class="btn-feature admin">Admin Login</a>
            </div>

            <div class="feature-card" style="--delay: 2;">
                <div class="feature-icon">⚡</div>
                <h3>Super Admin</h3>
                <p>Full system control & database access</p>
                <ul class="feature-list">
                    <li>Database administration</li>
                    <li>Admin management</li>
                    <li>System analytics</li>
                    <li>Audit logs</li>
                    <li>Backup & restore</li>
                    <li>Advanced settings</li>
                </ul>
                <a href="superadmin/login.php" target = _blank class="btn-feature superadmin">Super Admin</a>
            </div>
        </div>

        <div class="info-section" id="features">
            <style>
                .info-section {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
                    gap: 30px;
                    margin-bottom: 60px;
                }
                
                .info-card {
                    background: rgba(255, 255, 255, 0.95);
                    backdrop-filter: blur(10px);
                    padding: 40px;
                    border-radius: 20px;
                    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
                    transition: all 0.3s;
                }
                
                .info-card:hover {
                    transform: translateY(-5px);
                    box-shadow: 0 15px 50px rgba(0, 0, 0, 0.2);
                }
                
                .info-card h3 {
                    color: #1e293b;
                    font-size: 24px;
                    margin-bottom: 20px;
                    font-weight: 700;
                }
                
                .info-card p {
                    color: #475569;
                    line-height: 1.8;
                    font-size: 16px;
                }
                
                .features-list {
                    list-style: none;
                    padding: 0;
                }
                
                .features-list li {
                    color: #475569;
                    padding: 12px 0;
                    border-bottom: 1px solid #e2e8f0;
                    font-size: 16px;
                    display: flex;
                    align-items: center;
                    gap: 12px;
                }
                
                .features-list li:last-child {
                    border-bottom: none;
                }
                
                .contact-section {
                    background: rgba(255, 255, 255, 0.95);
                    backdrop-filter: blur(10px);
                    padding: 50px;
                    border-radius: 20px;
                    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
                    margin-bottom: 60px;
                }
                
                .contact-section h3 {
                    color: #1e293b;
                    font-size: 28px;
                    margin-bottom: 30px;
                    text-align: center;
                    font-weight: 700;
                }
                
                .contact-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                    gap: 30px;
                }
                
                .contact-item {
                    display: flex;
                    align-items: flex-start;
                    gap: 20px;
                    padding: 25px;
                    background: #f8fafc;
                    border-radius: 15px;
                    transition: all 0.3s;
                }
                
                .contact-item:hover {
                    background: #eef2ff;
                    transform: translateX(5px);
                }
                
                .contact-icon {
                    font-size: 36px;
                    flex-shrink: 0;
                }
                
                .contact-item strong {
                    display: block;
                    color: #1e293b;
                    font-size: 18px;
                    margin-bottom: 8px;
                }
                
                .contact-item p {
                    color: #64748b;
                    margin: 0;
                }
                
                .footer {
                    background: rgba(30, 41, 59, 0.95);
                    backdrop-filter: blur(10px);
                    color: white;
                    text-align: center;
                    padding: 40px 20px;
                    border-radius: 20px 20px 0 0;
                }
                
                .footer p {
                    margin: 10px 0;
                }
                
                .capabilities-section {
                    background: rgba(255, 255, 255, 0.95);
                    backdrop-filter: blur(10px);
                    padding: 50px;
                    border-radius: 20px;
                    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
                    margin-bottom: 60px;
                }
                
                .capabilities-section h2 {
                    text-align: center;
                    color: #1e293b;
                    font-size: 32px;
                    margin-bottom: 40px;
                    font-weight: 800;
                }
                
                .capabilities-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                    gap: 20px;
                }
                
                .capability-item {
                    text-align: center;
                    padding: 25px;
                    background: #f8fafc;
                    border-radius: 12px;
                    transition: all 0.3s;
                }
                
                .capability-item:hover {
                    background: linear-gradient(135deg, #eef2ff 0%, #faf5ff 100%);
                    transform: translateY(-5px);
                }
                
                .capability-item .icon {
                    font-size: 40px;
                    margin-bottom: 15px;
                }
                
                .capability-item h4 {
                    color: #1e293b;
                    margin-bottom: 10px;
                    font-size: 16px;
                }
                
                .capability-item p {
                    color: #64748b;
                    font-size: 13px;
                }
                
                @media (max-width: 768px) {
                    .hero-section h1 {
                        font-size: 32px;
                    }
                    
                    .navbar .nav-links {
                        display: none;
                    }
                    
                    .features-grid,
                    .info-section,
                    .stats-section {
                        grid-template-columns: 1fr;
                    }
                }
            </style>
            
            <div class="info-card">
                <h3>📊 About SR Colony Chitti</h3>
                <p>Our advanced chitti management system provides a complete digital solution for community members to track contributions, distributions, and interest calculations with 100% transparency. Built with modern technology to ensure reliability, security, and ease of use.</p>
            </div>

            <div class="info-card">
                <h3>✨ Core Features</h3>
                <ul class="features-list">
                    <li>📱 Mobile-responsive design</li>
                    <li>💰 Real-time payment tracking</li>
                    <li>📈 Automatic interest calculations</li>
                    <li>📅 Monthly status updates</li>
                    <li>🔒 Multi-level secure authentication</li>
                    <li>📊 Comprehensive reports & analytics</li>
                    <li>💾 Automated backup system</li>
                    <li>🔔 Notification system</li>
                </ul>
            </div>
            
            <div class="info-card">
                <h3>🎯 System Capabilities</h3>
                <ul class="features-list">
                    <li>🗄️ Database administration tools</li>
                    <li>📤 Export to PDF/SQL/Excel</li>
                    <li>📥 Bulk data import</li>
                    <li>🔍 Advanced search & filters</li>
                    <li>📝 Audit log tracking</li>
                    <li>⚙️ Customizable settings</li>
                    <li>👥 Multi-user access control</li>
                    <li>🌐 Cloud-ready infrastructure</li>
                </ul>
            </div>
        </div>

        <div class="capabilities-section">
            <h2>🚀 Platform Capabilities</h2>
            <div class="capabilities-grid">
                <div class="capability-item">
                    <div class="icon">�</div>
                    <h4>Member Features</h4>
                    <p>Dashboard, Payments, History, Profile</p>
                </div>
                <div class="capability-item">
                    <div class="icon">🛠️</div>
                    <h4>Admin Tools</h4>
                    <p>Management, Reports, Verification</p>
                </div>
                <div class="capability-item">
                    <div class="icon">⚡</div>
                    <h4>Super Admin</h4>
                    <p>DB Access, Analytics, Control</p>
                </div>
                <div class="capability-item">
                    <div class="icon">💳</div>
                    <h4>Transactions</h4>
                    <p>Track, Verify, Update</p>
                </div>
                <div class="capability-item">
                    <div class="icon">📊</div>
                    <h4>Analytics</h4>
                    <p>Charts, Graphs, Insights</p>
                </div>
                <div class="capability-item">
                    <div class="icon">🔐</div>
                    <h4>Security</h4>
                    <p>Encryption, Auth, Logs</p>
                </div>
                <div class="capability-item">
                    <div class="icon">📤</div>
                    <h4>Export</h4>
                    <p>PDF, SQL, Excel, Print</p>
                </div>
                <div class="capability-item">
                    <div class="icon">☁️</div>
                    <h4>Backup</h4>
                    <p>Auto, Manual, Restore</p>
                </div>
            </div>
        </div>

        <div class="contact-section" id="contact">
            <h3>📞 Contact Information</h3>
            <div class="contact-grid">
                <div class="contact-item">
                    <span class="contact-icon">📍</span>
                    <div>
                        <strong>Location</strong>
                        <p>SR Colony, Yerraguntlepalli<br>Pileru, Andhra Pradesh</p>
                    </div>
                </div>
                <div class="contact-item">
                    <span class="contact-icon">📧</span>
                    <div>
                        <strong>Email</strong>
                        <p>admin@srcolony.com<br>support@srcolony.com</p>
                    </div>
                </div>
                <div class="contact-item">
                    <span class="contact-icon">📱</span>
                    <div>
                        <strong>Phone</strong>
                        <p>+91 XXXXX XXXXX<br>Available 24/7</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="footer">
        <p style="font-size: 1.2em; font-weight: bold; margin-bottom: 15px;">SR Colony Chitti Management System</p>
        <p>&copy; 2025 Yerraguntlepalli, Pileru | All Rights Reserved</p>
        <p style="font-size: 0.9em; margin-top: 10px; opacity: 0.8;">Powered by Advanced Digital Solutions</p>
        <p style="font-size: 0.85em; margin-top: 15px;">
            <a href="#" style="color: #fbbf24; text-decoration: none; margin: 0 10px;">Privacy Policy</a> | 
            <a href="#" style="color: #fbbf24; text-decoration: none; margin: 0 10px;">Terms of Service</a> | 
            <a href="#" style="color: #fbbf24; text-decoration: none; margin: 0 10px;">Help</a>
        </p>
    </footer>

    <script src="assets/js/script.js"></script>
    <script>
        // Smooth scrolling for navigation links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
        
        // Add animation on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -100px 0px'
        };
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);
        
        document.querySelectorAll('.feature-card, .info-card, .stat-card').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(30px)';
            el.style.transition = 'all 0.6s ease';
            observer.observe(el);
        });
        
        // Particle effect
        const particlesContainer = document.querySelector('.particles');
        if (particlesContainer) {
            for (let i = 0; i < 50; i++) {
                const particle = document.createElement('div');
                particle.style.position = 'absolute';
                particle.style.width = Math.random() * 5 + 'px';
                particle.style.height = particle.style.width;
                particle.style.background = 'rgba(255, 255, 255, 0.5)';
                particle.style.borderRadius = '50%';
                particle.style.left = Math.random() * 100 + '%';
                particle.style.top = Math.random() * 100 + '%';
                particle.style.animation = `float ${Math.random() * 10 + 5}s infinite ease-in-out`;
                particlesContainer.appendChild(particle);
            }
        }
        
        // Add floating animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes float {
                0%, 100% { transform: translateY(0px); }
                50% { transform: translateY(-20px); }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
