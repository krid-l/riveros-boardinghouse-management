<?php
// includes/tenant_profile.php
//
// A tenant's personal details: the same fields, with the same checks, wherever they are
// entered or changed. The admin fills them in when adding a tenant (and can edit them later);
// the tenant sees and updates the same details under Profile Settings. Only the name is the
// admin's to change: it appears on receipts.

require_once __DIR__ . '/sms.php';

const TENANT_GENDERS = ['Male', 'Female', 'Prefer not to say'];
const TENANT_RELATIONSHIPS = ['Parent', 'Guardian', 'Sibling', 'Spouse', 'Relative', 'Friend'];

/** The profile columns, as they are named in the tenants table and in the forms. */
const TENANT_PROFILE_COLUMNS = [
    'first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth', 'contact_number', 'email',
    'address', 'occupation', 'emergency_name', 'emergency_relationship', 'emergency_phone',
];

/**
 * Read and check the profile fields of a posted form.
 * @param bool $withName false for the tenant's own form (the name can't be changed there)
 * @return array column => value (null for an empty optional field)
 * @throws Exception with a message for the page when something is not valid
 */
function tenantProfileFromPost(array $post, bool $withName = true): array {
    $text = function (string $key, int $max) use ($post): ?string {
        $value = trim((string)($post[$key] ?? ''));
        $value = preg_replace('/\s+/u', ' ', $value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    };
    $out = [];

    if ($withName) {
        $out['first_name'] = $text('first_name', 50);
        $out['middle_name'] = $text('middle_name', 50);
        $out['last_name'] = $text('last_name', 50);
        if ($out['first_name'] === null || $out['last_name'] === null) {
            throw new Exception('First name and last name are required.');
        }
    }

    $gender = $text('gender', 20);
    $out['gender'] = in_array($gender, TENANT_GENDERS, true) ? $gender : null;

    $dob = $text('date_of_birth', 10);
    if ($dob !== null) {
        $d = DateTime::createFromFormat('Y-m-d', $dob);
        if (!$d || $d->format('Y-m-d') !== $dob || $dob >= date('Y-m-d') || $dob < '1900-01-01') {
            throw new Exception('Please enter a valid date of birth.');
        }
    }
    $out['date_of_birth'] = $dob;

    $out['contact_number'] = validatedMobileInput($post['contact_number'] ?? '');
    if ($out['contact_number'] === '') {
        throw new Exception('A mobile number is required: rent reminders and receipts are sent to it.');
    }

    $email = $text('email', 120);
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Please enter a valid email address, or leave it empty.');
    }
    $out['email'] = $email;

    $address = trim((string)($post['address'] ?? ''));
    $out['address'] = $address === '' ? null : mb_substr($address, 0, 500);
    $out['occupation'] = $text('occupation', 100);
    $out['emergency_name'] = $text('emergency_name', 100);
    $out['emergency_relationship'] = $text('emergency_relationship', 50);
    $out['emergency_phone'] = validatedMobileInput($post['emergency_phone'] ?? '', 'emergency contact number') ?: null;

    return $out;
}

/** "Juan S. Dela Cruz" (with the middle initial) or "Juan Dela Cruz". */
function tenantFullName(array $t, bool $withMiddle = false): string {
    $middle = trim((string)($t['middle_name'] ?? ''));
    $parts = [$t['first_name'] ?? ''];
    if ($withMiddle && $middle !== '') {
        $parts[] = mb_strtoupper(mb_substr($middle, 0, 1)) . '.';
    }
    $parts[] = $t['last_name'] ?? '';
    return trim(implode(' ', array_filter($parts, fn($p) => $p !== '')));
}

/** "Maria Cruz (Parent) · 0917 123 4567", or what older records kept in one field. */
function tenantEmergencyText(array $t): string {
    $name = trim((string)($t['emergency_name'] ?? ''));
    $rel = trim((string)($t['emergency_relationship'] ?? ''));
    $phone = trim((string)($t['emergency_phone'] ?? ''));
    if ($name === '' && $phone === '') {
        return trim((string)($t['emergency_contact'] ?? ''));
    }
    $normalized = normalizePhMobile($phone);
    $phone = $normalized ? formatPhMobile($normalized) : $phone;
    return trim($name . ($rel !== '' ? " ($rel)" : '') . ($phone !== '' ? ($name !== '' ? ' · ' : '') . $phone : ''));
}

/** Age in years from a date of birth, or null. */
function tenantAge(?string $dob): ?int {
    if (!$dob) return null;
    return (new DateTime($dob))->diff(new DateTime('today'))->y;
}

/**
 * The profile inputs, laid out in sections. Used by the admin's Add / Edit Tenant forms and
 * by the tenant's Profile Settings, so they always ask for the same things.
 *
 * @param array  $v        current values (column => value)
 * @param string $idPrefix prefix for the inputs' ids (several forms can be on one page)
 * @param bool   $nameEditable false on the tenant's own page: the name is shown, read-only
 */
function tenantProfileFormFields(array $v, string $idPrefix, bool $nameEditable = true): string {
    $e = fn($key) => htmlspecialchars((string)($v[$key] ?? ''), ENT_QUOTES);
    $id = fn($key) => htmlspecialchars($idPrefix . '_' . $key);
    $ro = $nameEditable ? '' : ' readonly tabindex="-1"';
    $roClass = $nameEditable ? '' : ' bg-light';
    $req = $nameEditable ? ' required' : '';

    $genders = '<option value="">Select</option>';
    foreach (TENANT_GENDERS as $g) {
        $genders .= '<option' . (($v['gender'] ?? '') === $g ? ' selected' : '') . '>' . $g . '</option>';
    }
    $relList = '';
    foreach (TENANT_RELATIONSHIPS as $r) {
        $relList .= '<option value="' . $r . '">';
    }

    return '
    <div class="profile-section-title">Personal information</div>
    <div class="row g-2 mb-2">
        <div class="col-sm-4"><label for="' . $id('first_name') . '">First name' . ($nameEditable ? ' <span class="text-danger">*</span>' : '') . '</label>
            <input type="text" class="form-control' . $roClass . '" id="' . $id('first_name') . '" name="first_name" maxlength="50" placeholder="e.g., Juan" value="' . $e('first_name') . '"' . $req . $ro . '></div>
        <div class="col-sm-4"><label for="' . $id('middle_name') . '">Middle name</label>
            <input type="text" class="form-control' . $roClass . '" id="' . $id('middle_name') . '" name="middle_name" maxlength="50" placeholder="e.g., Santos" value="' . $e('middle_name') . '"' . $ro . '></div>
        <div class="col-sm-4"><label for="' . $id('last_name') . '">Last name' . ($nameEditable ? ' <span class="text-danger">*</span>' : '') . '</label>
            <input type="text" class="form-control' . $roClass . '" id="' . $id('last_name') . '" name="last_name" maxlength="50" placeholder="e.g., Dela Cruz" value="' . $e('last_name') . '"' . $req . $ro . '></div>
        ' . ($nameEditable ? '' : '<div class="col-12"><div class="form-text mt-0" style="font-size:0.65rem;">Your name is on your receipts. Ask the admin if it needs correcting.</div></div>') . '
        <div class="col-sm-4"><label for="' . $id('gender') . '">Gender</label>
            <select class="form-select" id="' . $id('gender') . '" name="gender">' . $genders . '</select></div>
        <div class="col-sm-4"><label for="' . $id('date_of_birth') . '">Date of birth</label>
            <input type="date" class="form-control" id="' . $id('date_of_birth') . '" name="date_of_birth" max="' . date('Y-m-d', strtotime('-1 day')) . '" value="' . $e('date_of_birth') . '"></div>
        <div class="col-sm-4"><label for="' . $id('occupation') . '">Occupation / School</label>
            <input type="text" class="form-control" id="' . $id('occupation') . '" name="occupation" maxlength="100" placeholder="e.g., Student, BSIT" value="' . $e('occupation') . '"></div>
        <div class="col-sm-6"><label for="' . $id('contact_number') . '">Mobile number <span class="text-danger">*</span></label>
            <input type="tel" class="form-control" id="' . $id('contact_number') . '" name="contact_number" inputmode="tel" placeholder="e.g., 0912 345 6789" value="' . $e('contact_number') . '" required></div>
        <div class="col-sm-6"><label for="' . $id('email') . '">Email</label>
            <input type="email" class="form-control" id="' . $id('email') . '" name="email" maxlength="120" placeholder="e.g., juan@email.com" value="' . $e('email') . '"></div>
        <div class="col-12"><label for="' . $id('address') . '">Home address</label>
            <textarea class="form-control" id="' . $id('address') . '" name="address" rows="2" maxlength="500" placeholder="House no., street, barangay, town / city, province">' . $e('address') . '</textarea></div>
    </div>
    <div class="profile-section-title">Emergency contact</div>
    <div class="row g-2 mb-1">
        <div class="col-sm-5"><label for="' . $id('emergency_name') . '">Contact person</label>
            <input type="text" class="form-control" id="' . $id('emergency_name') . '" name="emergency_name" maxlength="100" placeholder="Full name" value="' . $e('emergency_name') . '"></div>
        <div class="col-sm-3"><label for="' . $id('emergency_relationship') . '">Relationship</label>
            <input type="text" class="form-control" id="' . $id('emergency_relationship') . '" name="emergency_relationship" maxlength="50" list="' . $id('relationships') . '" placeholder="e.g., Parent" value="' . $e('emergency_relationship') . '">
            <datalist id="' . $id('relationships') . '">' . $relList . '</datalist></div>
        <div class="col-sm-4"><label for="' . $id('emergency_phone') . '">Their mobile number</label>
            <input type="tel" class="form-control" id="' . $id('emergency_phone') . '" name="emergency_phone" inputmode="tel" placeholder="e.g., 0998 765 4321" value="' . $e('emergency_phone') . '"></div>
    </div>';
}
