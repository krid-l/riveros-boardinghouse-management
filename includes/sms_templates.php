<?php
// includes/sms_templates.php
//
// The text messages tenants get, worded as the owner asked. Amounts are written "PHP 2,000.00"
// rather than with the peso sign: one character outside the basic SMS alphabet (like ₱) makes
// the whole message use the 70-characters-a-part encoding instead of 160, so it would cost
// two to three times as many SMS credits.

require_once __DIR__ . '/sms.php';

/** "RIVEROS BOARDING HOUSE": the first line of every message. */
function smsHeader(PDO $pdo): string {
    return mb_strtoupper(smsConfig($pdo)['name']);
}

function smsPeso(float $amount): string {
    return 'PHP ' . number_format($amount, 2);
}

function smsDate(?string $date): string {
    return $date ? date('F j, Y', strtotime($date)) : 'Pending';
}

/** Sent when the admin adds a tenant: their room, what to pay on moving in and how to log in. */
function smsRegistrationMessage(PDO $pdo, string $name, ?string $room, ?string $moveIn, ?float $advance, ?float $deposit,
                                string $username, string $password): string {
    return smsHeader($pdo) . "\n\n"
         . "Welcome, $name! Your registration is successful.\n\n"
         . 'Assigned Room: ' . ($room !== null ? "Room $room" : 'Pending') . "\n"
         . 'Move-in Date: ' . smsDate($moveIn) . "\n"
         . 'Advance Payment: ' . ($advance !== null ? smsPeso($advance) : 'Pending') . "\n"
         . 'Deposit: ' . ($deposit !== null ? smsPeso($deposit) : 'Pending') . "\n\n"
         . "Username: $username\n"
         . "Temporary Password: $password\n\n"
         . 'Please keep your login credentials secure and change your temporary password after your first login. Thank you!';
}

/** Before (and on) the due date. */
function smsDueReminderMessage(PDO $pdo, string $name, float $rent, string $dueDate, float $balance): string {
    return smsHeader($pdo) . "\n\n"
         . "Hello, $name! This is a reminder that your monthly rent of " . smsPeso($rent) . ' is due on ' . smsDate($dueDate) . ".\n\n"
         . 'Outstanding Balance: ' . smsPeso($balance) . "\n\n"
         . 'Please submit your payment and upload your proof of payment through the system. Thank you!';
}

/** After the admin verifies a payment (or records the move-in payment). */
function smsPaymentVerifiedMessage(PDO $pdo, string $name, float $amount, string $method, string $paymentDate, float $remaining): string {
    return smsHeader($pdo) . "\n\n"
         . "Hello, $name! Your payment has been verified and approved.\n\n"
         . 'Amount Paid: ' . smsPeso($amount) . "\n"
         . "Payment Method: $method\n"
         . 'Payment Date: ' . smsDate($paymentDate) . "\n"
         . 'Remaining Balance: ' . smsPeso(max(0, $remaining)) . "\n\n"
         . 'Thank you for your payment! Please keep this message for your records.';
}

/** Rent past its due date (at most once a week until paid). */
function smsOverdueMessage(PDO $pdo, string $name, float $overdue, ?string $dueDate): string {
    return smsHeader($pdo) . "\n\n"
         . "Hello, $name. Our records show that your rent payment of " . smsPeso($overdue) . ' is overdue'
         . ($dueDate ? ' as of ' . smsDate($dueDate) : '') . ".\n\n"
         . 'Please settle your outstanding balance as soon as possible. If you have already paid, kindly contact the management for verification.'
         . "\n\nThank you.";
}

/** When the admin rejects a submitted payment (not one of the owner's templates; same style). */
function smsPaymentRejectedMessage(PDO $pdo, string $name, float $amount, string $reference): string {
    return smsHeader($pdo) . "\n\n"
         . "Hello, $name. Your payment of " . smsPeso($amount) . ($reference !== '' ? " (ref $reference)" : '') . ' was not approved.'
         . "\n\nPlease check the amount and reference number and submit it again, or contact the management. Thank you.";
}

/** The admin approved using the deposit for the tenant's last month. */
function smsDepositApprovedMessage(PDO $pdo, string $name, float $deposit, string $monthLabel, float $balance): string {
    return smsHeader($pdo) . "\n\n"
         . "Hello, $name! Your request to use your deposit of " . smsPeso($deposit) . " for your rent for $monthLabel has been approved.\n\n"
         . 'Remaining Balance: ' . smsPeso(max(0, $balance)) . "\n\nThank you!";
}

/** The admin declined it. */
function smsDepositDeclinedMessage(PDO $pdo, string $name, string $monthLabel, string $note): string {
    return smsHeader($pdo) . "\n\n"
         . "Hello, $name. Your request to use your deposit for your rent for $monthLabel was not approved."
         . ($note !== '' ? "\n\nReason: $note" : '')
         . "\n\nPlease contact the management if you have questions. Thank you.";
}
