<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/app/staff.php';
require_method('GET');
$staff = require_staff();
$status = $_GET['status'] ?? 'active';
$allowed = ['active','open','needs_human','closed','all'];
if (!is_string($status) || !in_array($status,$allowed,true)) json_response(['error'=>'invalid_status_filter'],422);
$where = '';
$params = [];
if ($status === 'active') $where = "WHERE c.status IN ('open','needs_human')";
elseif ($status !== 'all') { $where = 'WHERE c.status=:status'; $params['status']=$status; }
$sql = "SELECT c.public_id,c.status,c.context_url,c.context_sku,c.subject,c.last_message_at,c.created_at,
 cu.display_name AS customer_name,cu.email AS customer_email,
 assigned.display_name AS assigned_staff,
 (SELECT m.body FROM messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_message,
 (SELECT m.sender_type FROM messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_sender_type,
 (SELECT MAX(m.id) FROM messages m WHERE m.conversation_id=c.id) AS last_message_id,
 (SELECT COUNT(*) FROM messages m WHERE m.conversation_id=c.id AND m.sender_type='customer' AND m.id>COALESCE(c.staff_last_read_message_id,0)) AS unread_count
 FROM conversations c INNER JOIN customers cu ON cu.id=c.customer_id
 LEFT JOIN staff_users assigned ON assigned.id=c.assigned_staff_id
 {$where} ORDER BY c.last_message_at DESC LIMIT 100";
$query = db()->prepare($sql);
$query->execute($params);
$rows = $query->fetchAll();
foreach ($rows as &$row) $row['unread_count']=(int)$row['unread_count'];
unset($row);
json_response(['conversations'=>$rows]);
