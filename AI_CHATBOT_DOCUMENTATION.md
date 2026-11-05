# AI Chatbot Integration - Complete Documentation

## Overview
Advanced AI-powered chatbot integrated across all portals (Super Admin, Admin, Member) with role-based data access and intelligent query processing.

## Features

### 🤖 Intelligent Query Understanding
- **Natural Language Processing**: Understands user intent from conversational queries
- **11 Intent Categories**: Member info, transactions, statistics, verification, payments, yearly/monthly analysis, and more
- **Context-Aware Responses**: Provides relevant data based on user role and permissions

### 🔐 Role-Based Access Control
- **Super Admin**: Full system access - all members, transactions, statistics
- **Admin**: Same as Super Admin
- **Member**: Personal data only - own transactions and profile information

### 📊 Advanced Features
1. **Data Visualization**: Auto-generates charts for payment modes and monthly trends
2. **Smart Suggestions**: Context-based follow-up questions
3. **Data Tables**: Displays transaction details in formatted tables
4. **Real-time Updates**: Live data from database
5. **Responsive Design**: Works on desktop and mobile devices
6. **Dark Mode Support**: Automatically adapts to theme

## Intent Categories

### 1. Member Information (`member_info`)
**Triggers**: member, contact, phone, email, address, aadhar
- **Super/Admin**: Total, active, inactive member counts
- **Member**: Personal profile details

**Example Queries**:
- "How many members do we have?"
- "Show member statistics"
- "What is my contact information?"

### 2. Transaction Summary (`transaction_summary`)
**Triggers**: total, sum, collection, distribution, amount
- Shows total transactions, collection, distribution, interest, net amount
- Calculates surplus/deficit

**Example Queries**:
- "What is the total collection?"
- "Show me the transaction summary"
- "What's our net balance?"

### 3. Transaction Details (`transaction_details`)
**Triggers**: transaction, payment, history, record
- Lists recent transactions with member names, dates, amounts
- Limited to 10 (member) or 20 (admin) records

**Example Queries**:
- "Show recent transactions"
- "What are my latest payments?"
- "Transaction history"

### 4. Year Summary (`year_summary`)
**Triggers**: year, annual, yearly
- Breakdown by academic year with transaction counts and totals
- Shows active/inactive years

**Example Queries**:
- "Show year-wise summary"
- "Annual collection report"
- "Compare years"

### 5. Statistics (`statistics`)
**Triggers**: stat, average, count, how many
- Total members, transactions
- Average, max, min collection amounts

**Example Queries**:
- "Show statistics"
- "What's the average collection?"
- "How many transactions?"

### 6. Recent Activity (`recent_activity`)
**Triggers**: recent, latest, last, newest
- Last 10 transactions with timestamps

**Example Queries**:
- "Show recent activity"
- "What's the latest transaction?"
- "Recent payments"

### 7. Verification Status (`verification`)
**Triggers**: verify, verified, unverified, pending
- Total, verified, unverified counts
- Verification percentage

**Example Queries**:
- "Verification status"
- "How many verified transactions?"
- "Show pending verifications"

### 8. Payment Modes (`payment_modes`)
**Triggers**: cash, online, cheque, bank transfer, payment mode
- Breakdown by payment method
- Transaction counts and totals per mode
- **Generates pie chart**

**Example Queries**:
- "Payment mode breakdown"
- "Show cash vs online payments"
- "How do people pay?"

### 9. Monthly Analysis (`monthly`)
**Triggers**: month names (january, february, etc.)
- Monthly transaction counts and collections
- **Generates bar chart**

**Example Queries**:
- "Monthly collection report"
- "Show January transactions"
- "Month-wise breakdown"

## Technical Architecture

### Files Structure
```
SRCOLONY-VIEWS/
├── includes/
│   ├── chatbot.php              # Backend API handler
│   └── chatbot-widget.php       # Frontend widget UI
├── admin/
│   └── dashboard-new.php        # Admin portal (chatbot included)
├── member/
│   └── dashboard-new.php        # Member portal (chatbot included)
└── superadmin/
    └── includes/
        └── navbar.php           # Superadmin portal (chatbot included)
```

### Backend API (`chatbot.php`)
- **Endpoint**: `/includes/chatbot.php`
- **Method**: POST
- **Request**: `{ "query": "user question" }`
- **Response**:
```json
{
  "answer": "Natural language response",
  "data": { /* Relevant data */ },
  "suggestions": ["Follow-up question 1", "..."],
  "charts": [{ "type": "pie", "title": "...", "data": [...] }]
}
```

### Frontend Widget (`chatbot-widget.php`)
- **Fixed Position**: Bottom-right corner
- **Floating Button**: 60x60px with gradient
- **Chat Window**: 400x600px (responsive on mobile)
- **Auto-scroll**: Messages scroll to bottom
- **Typing Indicator**: Animated dots while processing

## Usage Examples

### Super Admin Queries
```
"How many members are active?"
→ Returns: Total members with active/inactive breakdown

"What is the total collection this year?"
→ Returns: Year-wise collection with comparison

"Show payment mode statistics"
→ Returns: Breakdown + pie chart
```

### Admin Queries
```
"Show recent unverified transactions"
→ Returns: Verification status + recent records

"Monthly collection trends"
→ Returns: Month-wise data + bar chart

"Average collection amount"
→ Returns: Statistics with avg, max, min
```

### Member Queries
```
"What are my recent transactions?"
→ Returns: Personal transaction history (last 10)

"Show my profile details"
→ Returns: Name, phone, email, status

"How much did I collect this month?"
→ Returns: Personal monthly summary
```

## Customization

### Adding New Intents
Edit `chatbot.php`, add to `analyzeIntent()` function:
```php
$intents = [
    'your_intent' => ['keyword1', 'keyword2'],
    // ... existing intents
];
```

Then add case in `fetchRelevantData()` and `generateResponse()`.

### Styling
Edit `chatbot-widget.php` CSS:
- `.chatbot-button`: Change colors, size
- `.chatbot-window`: Adjust dimensions
- `.chatbot-header`: Modify gradient

### Response Templates
Edit `generateResponse()` in `chatbot.php`:
```php
case 'your_intent':
    return "Your custom response format";
```

## Security Features
- Session validation (requires login)
- Role-based data filtering
- SQL injection protection (prepared statements)
- XSS prevention (htmlspecialchars)
- Error handling with user-friendly messages

## Performance
- Caches database results
- Limits query results (10-20 records)
- Efficient SQL with JOINs
- Minimal JavaScript footprint

## Browser Support
- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+

## Mobile Responsive
- Window: Full-width on mobile (<768px)
- Button: Fixed position maintained
- Touch-friendly: 60px minimum tap target

## Future Enhancements
1. ✅ Natural language understanding
2. ✅ Chart generation
3. ✅ Role-based access
4. ⏳ Multi-language support
5. ⏳ Export chat history
6. ⏳ Voice input
7. ⏳ Advanced analytics predictions
8. ⏳ Integration with external AI (GPT-4)

## Troubleshooting

### Chatbot not appearing
- Check session is active
- Verify widget is included: `<?php include '../includes/chatbot-widget.php'; ?>`
- Check z-index conflicts

### No response from chatbot
- Verify `chatbot.php` path is correct
- Check database connection
- Inspect browser console for errors
- Verify user is logged in

### Wrong data returned
- Check user role in session
- Verify SQL queries in `fetchRelevantData()`
- Test database queries directly

## API Reference

### Request Format
```javascript
{
    "query": "String - user question",
    "context": {} // Optional - future use
}
```

### Response Format
```javascript
{
    "answer": "String - formatted response",
    "data": {
        // Object - varies by intent
        "summary": {...},
        "transactions": [...],
        "stats": {...}
    },
    "suggestions": [
        "String - follow-up question 1",
        "String - follow-up question 2"
    ],
    "charts": [
        {
            "type": "pie|bar",
            "title": "Chart title",
            "data": [{"label": "...", "value": 123}]
        }
    ],
    "error": "String - only if error occurred"
}
```

---

**Version**: 1.0  
**Created**: November 23, 2025  
**Status**: ✅ Fully Functional - No Errors
