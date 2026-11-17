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
 cu.display_name AS customer_name,cu.email AS customer_email,cu.external_user_id AS customer_account_id,
 assigned.display_name AS assigned_staff,\n (SELECT v.make FROM conversation_vehicles v WHERE v.conversation_id=c.id ORDER BY v.id DESC LIMIT 1) AS vehicle_make,\n (SELECT v.model FROM conversation_vehicles v WHERE v.conversation_id=c.id ORDER BY v.id DESC LIMIT 1) AS vehicle_model,\n (SELECT v.model_year FROM conversation_vehicles v WHERE v.conversation_id=c.id ORDER BY v.id DESC LIMIT 1) AS vehicle_year,\n (SELECT v.engine FROM conversation_vehicles v WHERE v.conversation_id=c.id ORDER BY v.id DESC LIMIT 1) AS vehicle_engine,\n (SELECT v.transmission FROM conversation_vehicles v WHERE v.conversation_id=c.id ORDER BY v.id DESC LIMIT 1) AS vehicle_transmission,\n (SELECT v.vin_last4 FROM conversation_vehicles v WHERE v.conversation_id=c.id ORDER BY v.id DESC LIMIT 1) AS vehicle_vin_last4,\n (SELECT v.product_id FROM conversation_vehicles v WHERE v.conversation_id=c.id ORDER BY v.id DESC LIMIT 1) AS vehicle_product_id,\n (SELECT p.product_id FROM conversation_product_context p WHERE p.conversation_id=c.id LIMIT 1) AS selected_product_id,\n (SELECT p.product_name FROM conversation_product_context p WHERE p.conversation_id=c.id LIMIT 1) AS selected_product_name,\n (SELECT p.sku FROM conversation_product_context p WHERE p.conversation_id=c.id LIMIT 1) AS selected_product_sku,\n (SELECT p.product_permalink FROM conversation_product_context p WHERE p.conversation_id=c.id LIMIT 1) AS selected_product_permalink,\n
 (SELECT m.body FROM messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_message,
 (SELECT m.sender_type FROM messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_sender_type,
 (SELECT MAX(m.id) FROM messages m WHERE m.conversation_id=c.id) AS last_message_id,
 (SELECT COUNT(*) FROM messages m WHERE m.conversation_id=c.id AND m.sender_type IN ('customer','customer_event') AND m.id>COALESCE(c.staff_last_read_message_id,0)) AS unread_count
 FROM conversations c INNER JOIN customers cu ON cu.id=c.customer_id
 LEFT JOIN staff_users assigned ON assigned.id=c.assigned_staff_id
 {$where} ORDER BY c.last_message_at DESC LIMIT 100";
$query = db()->prepare($sql);
$query->execute($params);
$rows = $query->fetchAll();
foreach ($rows as &$row) {
 $row['unread_count']=(int)$row['unread_count'];
 $row['vehicle_product_id']=$row['vehicle_product_id']===null?null:(int)$row['vehicle_product_id'];
 $row['selected_product_id']=$row['selected_product_id']===null?null:(int)$row['selected_product_id'];
 $parts=[$row['vehicle_make']??null,isset($row['vehicle_model'],$row['vehicle_year'])?$row['vehicle_model'].' '.$row['vehicle_year']:null,$row['vehicle_engine']??null,$row['vehicle_transmission']??null,!empty($row['vehicle_vin_last4'])?'VIN …'.$row['vehicle_vin_last4']:null];
 $row['vehicle_summary']=implode(' · ',array_values(array_filter($parts,static fn($part)=>is_string($part)&&$part!=='')));
 unset($row['vehicle_make'],$row['vehicle_model'],$row['vehicle_year'],$row['vehicle_engine'],$row['vehicle_transmission'],$row['vehicle_vin_last4']);
}
unset($row);
json_response(['conversations'=>$rows]);
