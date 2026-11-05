<?php
/**
 * Add Event Page
 * Create new events with budget tracking
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../session.php';
require_once '../../../config/config.php';
require_once '../../../includes/logo-helper.php';

$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');

// Check if events table exists
$tableCheckStmt = $pdo->query("SHOW TABLES LIKE 'events'");
if ($tableCheckStmt->rowCount() === 0) {
    header('Location: ../setup-events.php');
    exit;
}

$message = '';
$messageType = '';

// Fetch academic years
$yearsStmt = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY YearName DESC");
$academicYears = $yearsStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $eventName = trim($_POST['event_name'] ?? '');
    $eventDescription = trim($_POST['event_description'] ?? '');
    $eventDate = $_POST['event_date'] ?? '';
    $eventEndDate = $_POST['event_end_date'] ?: null;
    $venue = trim($_POST['venue'] ?? '');
    $eventType = $_POST['event_type'] ?? 'General';
    $budgetAmount = floatval($_POST['budget_amount'] ?? 0);
    $yearId = $_POST['year_id'] ?: null;
    $organizer = trim($_POST['organizer'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $status = $_POST['status'] ?? 'Planned';
    
    if ($eventName && $eventDate) {
        try {
            $insertStmt = $pdo->prepare("
                INSERT INTO events (
                    EventName, EventDescription, EventDate, EventEndDate,
                    Venue, EventType, Status, BudgetAmount, YearID,
                    Organizer, ContactNumber, Notes, CreatedBy
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $insertStmt->execute([
                $eventName, $eventDescription, $eventDate, $eventEndDate,
                $venue, $eventType, $status, $budgetAmount, $yearId,
                $organizer, $contactNumber, $notes, $_SESSION['superadmin_id']
            ]);
            
            $newEventId = $pdo->lastInsertId();
            
            // Redirect to view event or add transactions
            header("Location: view-event.php?id=$newEventId&success=1");
            exit;
            
        } catch (PDOException $e) {
            $message = "Error creating event: " . $e->getMessage();
            $messageType = 'error';
        }
    } else {
        $message = "Please fill in all required fields.";
        $messageType = 'error';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Event - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../shared-styles.css">
    <style>
        .page-header {
            margin-bottom: 30px;
        }
        
        .page-header h1 {
            font-size: 28px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .page-header h1 i {
            color: #8b5cf6;
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            text-decoration: none;
            margin-bottom: 20px;
        }
        
        .back-link:hover { color: #8b5cf6; }
        
        .form-card {
            background: var(--bg-secondary);
            border-radius: 16px;
            padding: 35px;
            box-shadow: var(--shadow);
            max-width: 900px;
            margin: 0 auto;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .form-group.full-width {
            grid-column: span 2;
        }
        
        .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
        }
        
        .form-group label .required {
            color: #ef4444;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 12px 14px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 15px;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #8b5cf6;
            outline: none;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }
        
        .amount-input {
            position: relative;
        }
        
        .amount-input span {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-weight: 600;
        }
        
        .amount-input input {
            padding-left: 30px;
        }
        
        .btn-container {
            display: flex;
            gap: 15px;
            margin-top: 25px;
        }
        
        .btn {
            padding: 14px 30px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }
        
        .btn-secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }
        
        .alert {
            padding: 14px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert.error {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        .event-type-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
        }
        
        .type-option {
            padding: 12px;
            text-align: center;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .type-option:hover {
            border-color: #8b5cf6;
        }
        
        .type-option.selected {
            border-color: #8b5cf6;
            background: rgba(139, 92, 246, 0.1);
        }
        
        .type-option input {
            display: none;
        }
        
        .type-option i {
            font-size: 20px;
            display: block;
            margin-bottom: 6px;
            color: #8b5cf6;
        }
        
        .type-option span {
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-options {
            display: flex;
            gap: 10px;
        }
        
        .status-option {
            flex: 1;
            padding: 12px;
            text-align: center;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .status-option:hover {
            border-color: var(--text-secondary);
        }
        
        .status-option.selected {
            color: white;
        }
        
        .status-option.selected.planned { background: #3b82f6; border-color: #3b82f6; }
        .status-option.selected.ongoing { background: #10b981; border-color: #10b981; }
        .status-option.selected.completed { background: #8b5cf6; border-color: #8b5cf6; }
        .status-option.selected.cancelled { background: #ef4444; border-color: #ef4444; }
        
        .status-option input {
            display: none;
        }
        
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .form-group.full-width {
                grid-column: span 1;
            }
            
            .event-type-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .status-options {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
        <div class="navbar-content">
            <div class="navbar-brand">
                <i class="fas fa-calendar-star"></i>
                <span>Event Management</span>
            </div>
            <div class="navbar-user">
                <button id="themeToggle" class="theme-toggle">
                    <i id="themeIcon" class="fas fa-moon"></i>
                    <span id="themeText">Dark</span>
                </button>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($superadmin_name); ?></div>
                    <div class="user-role">Event Manager</div>
                </div>
                <a href="dashboard.php" class="btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i>
                    <span>Dashboard</span>
                </a>
                <?php include "../../includes/datetime-widget.php"; ?>
            </div>
        </div>
    </nav>
    
    <div class="container">
        <a href="manage-events.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Back to Events
        </a>
        
        <div class="page-header">
            <h1><i class="fas fa-plus-circle"></i> Create New Event</h1>
            <p style="color: var(--text-secondary); margin-top: 8px;">Set up a new event with its own financial tracking</p>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="form-card">
            <form method="POST">
                <div class="form-grid">
                    <!-- Event Name -->
                    <div class="form-group full-width">
                        <label>Event Name <span class="required">*</span></label>
                        <input type="text" name="event_name" required placeholder="e.g., Annual Day Celebration 2026">
                    </div>
                    
                    <!-- Event Type -->
                    <div class="form-group full-width">
                        <label>Event Type</label>
                        <div class="event-type-grid">
                            <?php 
                            $eventTypes = ['Festival', 'Religious', 'Cultural', 'Sports', 'Meeting', 'Charity', 'Celebration', 'Other'];
                            $typeIcons = [
                                'Festival' => 'fa-star',
                                'Religious' => 'fa-pray',
                                'Cultural' => 'fa-theater-masks',
                                'Sports' => 'fa-futbol',
                                'Meeting' => 'fa-users',
                                'Charity' => 'fa-hand-holding-heart',
                                'Celebration' => 'fa-birthday-cake',
                                'Other' => 'fa-calendar-day'
                            ];
                            foreach ($eventTypes as $type): 
                            ?>
                                <label class="type-option <?php echo $type === 'Festival' ? 'selected' : ''; ?>">
                                    <input type="radio" name="event_type" value="<?php echo $type; ?>" <?php echo $type === 'Festival' ? 'checked' : ''; ?>>
                                    <i class="fas <?php echo $typeIcons[$type]; ?>"></i>
                                    <span><?php echo $type; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Description -->
                    <div class="form-group full-width">
                        <label>Description</label>
                        <textarea name="event_description" rows="3" placeholder="Brief description of the event..."></textarea>
                    </div>
                    
                    <!-- Dates -->
                    <div class="form-group">
                        <label>Start Date <span class="required">*</span></label>
                        <input type="date" name="event_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>End Date</label>
                        <input type="date" name="event_end_date">
                    </div>
                    
                    <!-- Venue -->
                    <div class="form-group">
                        <label>Venue</label>
                        <input type="text" name="venue" placeholder="Event location">
                    </div>
                    
                    <!-- Budget -->
                    <div class="form-group">
                        <label>Budget Amount</label>
                        <div class="amount-input">
                            <span>₹</span>
                            <input type="number" name="budget_amount" step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                    
                    <!-- Academic Year -->
                    <div class="form-group">
                        <label>Academic Year</label>
                        <select name="year_id">
                            <option value="">-- Select Year --</option>
                            <?php foreach ($academicYears as $year): ?>
                                <option value="<?php echo $year['YearID']; ?>">
                                    <?php echo htmlspecialchars($year['YearName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Organizer -->
                    <div class="form-group">
                        <label>Organizer</label>
                        <input type="text" name="organizer" placeholder="Person in charge">
                    </div>
                    
                    <!-- Contact -->
                    <div class="form-group">
                        <label>Contact Number</label>
                        <input type="tel" name="contact_number" placeholder="Phone number">
                    </div>
                    
                    <!-- Status -->
                    <div class="form-group full-width">
                        <label>Initial Status</label>
                        <div class="status-options">
                            <?php foreach (['Planned', 'Ongoing', 'Completed'] as $status): ?>
                                <label class="status-option <?php echo strtolower($status); ?> <?php echo $status === 'Planned' ? 'selected' : ''; ?>">
                                    <input type="radio" name="status" value="<?php echo $status; ?>" <?php echo $status === 'Planned' ? 'checked' : ''; ?>>
                                    <?php echo $status; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Notes -->
                    <div class="form-group full-width">
                        <label>Notes</label>
                        <textarea name="notes" rows="3" placeholder="Additional notes..."></textarea>
                    </div>
                </div>
                
                <div class="btn-container">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus-circle"></i>
                        Create Event
                    </button>
                    <a href="manage-events.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i>
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <script src="../../theme-manager.js"></script>
    <script>
        // Event type selection
        document.querySelectorAll('.type-option').forEach(option => {
            option.addEventListener('click', function() {
                document.querySelectorAll('.type-option').forEach(o => o.classList.remove('selected'));
                this.classList.add('selected');
            });
        });
        
        // Status selection
        document.querySelectorAll('.status-option').forEach(option => {
            option.addEventListener('click', function() {
                document.querySelectorAll('.status-option').forEach(o => o.classList.remove('selected'));
                this.classList.add('selected');
            });
        });
    </script>
</body>
</html>
