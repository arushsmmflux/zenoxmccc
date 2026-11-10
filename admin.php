<?php
session_start();
if (PHP_SAPI !== 'cli') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

const ADMIN_USER = 'admin';
const DEFAULT_ADMIN_PASS = 'admin123';

$root = __DIR__;
$configFile = $root . '/webinfo.json';
$productsFile = $root . '/api/products.php';
$dataFile = $root . '/api/products_data.json';
$activityFile = $root . '/admin_activity.json';
$adminPasswordFile = $root . '/admin_password.json';

function loadConfig($file) {
    $defaults = [
        'upi' => '', 'merchant_name' => 'Garena Top Up', 'store_name' => 'Garena Top Up',
        'support' => '', 'announcement' => '', 'maintenance' => false
    ];
    if (!is_file($file)) return $defaults;
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? array_merge($defaults, $data) : $defaults;
}
function saveConfig($file, $cfg) {
    return @file_put_contents($file, json_encode($cfg, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n", LOCK_EX) !== false;
}
function validUpi($upi) { return (bool)preg_match('/^[A-Za-z0-9._-]{2,100}@[A-Za-z0-9._-]{2,50}$/', $upi); }
function loadProducts($file, $dataFile=null) {
    $candidates = [];
    if ($dataFile && is_file($dataFile)) $candidates[] = $dataFile;
    $candidates[] = $file;
    foreach ($candidates as $path) {
        $raw = @file_get_contents($path);
        if ($raw === false) continue;
        $data = json_decode($raw, true);
        if (is_array($data) && isset($data['categories'])) {
            // Tag overrides are canonical for tag edits. This keeps tags persistent
            // even when the main product JSON is read-only on shared hosting.
            $overrideFile = __DIR__ . '/tag_overrides.json';
            if (is_file($overrideFile)) {
                $ov = json_decode((string)@file_get_contents($overrideFile), true);
                if (is_array($ov)) {
                    foreach ($data['categories'] as &$cat) {
                        foreach ($cat['items'] as &$item) {
                            $pid = (string)($item['id'] ?? '');
                            if (array_key_exists($pid, $ov)) $item['tag'] = $ov[$pid];
                        }
                    }
                    unset($cat, $item);
                }
            }
            return $data;
        }
    }
    return ['ok'=>true,'categories'=>[]];
}
function writeJsonFile($path, $json) {
    $dir = dirname($path);
    if (!is_dir($dir)) return false;
    // Shared hosting can reject rename()/atomic replacement even when direct writes work.
    // Write directly first and verify the saved bytes.
    $written = @file_put_contents($path, $json, LOCK_EX);
    if ($written === false) return false;
    clearstatcache(true, $path);
    $check = @file_get_contents($path);
    return $check !== false && trim($check) === trim($json);
}
function saveProducts($file, $data, $dataFile=null) {
    // IMPORTANT: products.php is the API endpoint and must remain PHP code.
    // Product data is persisted only in products_data.json.
    $target = $dataFile ?: $file;
    $json = json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
    if ($json === false || !is_dir(dirname($target))) return false;

    // Try to make an existing cPanel-owned data file writable when possible.
    if (is_file($target) && !is_writable($target)) @chmod($target, 0664);
    if (!is_file($target) && is_writable(dirname($target))) {
        @touch($target);
        @chmod($target, 0664);
    }

    return writeJsonFile($target, $json);
}
function logActivity($file, $action) {
    $items = [];
    if (is_file($file)) { $j = json_decode((string)file_get_contents($file), true); if (is_array($j)) $items = $j; }
    array_unshift($items, ['time'=>date('Y-m-d H:i:s'), 'action'=>$action]);
    $items = array_slice($items, 0, 40);
    @file_put_contents($file, json_encode($items, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n", LOCK_EX);
}
function loadActivity($file) {
    if (!is_file($file)) return [];
    $j = json_decode((string)file_get_contents($file), true);
    return is_array($j) ? $j : [];
}
function replaceInFiles($root, $oldUpi, $newUpi, $oldMerchant, $newMerchant) {
    $allowed = ['php','js','json','html','htm','txt'];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        $path = $file->getPathname();
        if (basename($path) === 'admin.php') continue;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) continue;
        $contents = @file_get_contents($path); if ($contents === false) continue;
        $updated = str_replace($oldUpi, $newUpi, $contents);
        if ($oldMerchant !== '' && $oldMerchant !== $newMerchant) $updated = str_replace($oldMerchant, $newMerchant, $updated);
        if ($updated !== $contents) @file_put_contents($path, $updated, LOCK_EX);
    }
}
function textLen($s) { return function_exists('mb_strlen') ? mb_strlen($s,'UTF-8') : strlen($s); }
function textSub($s,$start,$len) { return function_exists('mb_substr') ? mb_substr($s,$start,$len,'UTF-8') : substr($s,$start,$len); }
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function getAdminPasswordHash($file) {
    if (is_file($file)) {
        $j = json_decode((string)@file_get_contents($file), true);
        if (is_array($j) && !empty($j['hash']) && password_get_info($j['hash'])['algo'] !== 0) return $j['hash'];
    }
    return password_hash(DEFAULT_ADMIN_PASS, PASSWORD_DEFAULT);
}
function saveAdminPasswordHash($file, $hash) {
    $json = json_encode(['hash'=>$hash], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
    return @file_put_contents($file, $json, LOCK_EX) !== false;
}
function validAdminPassword($password) { return textLen($password) >= 6; }


if (isset($_GET['logout'])) { $_SESSION=[]; session_destroy(); header('Location: admin.php'); exit; }
$error=''; $success='';
if (isset($_POST['login'])) {
    $u=(string)($_POST['username']??''); $p=(string)($_POST['password']??'');
    if (hash_equals(ADMIN_USER,$u) && password_verify($p, getAdminPasswordHash($adminPasswordFile))) { session_regenerate_id(true); $_SESSION['admin_auth']=true; logActivity($activityFile,'Admin login'); header('Location: admin.php'); exit; }
    $error='Invalid username or password.';
}
$logged=!empty($_SESSION['admin_auth']);

if ($logged && $_SERVER['REQUEST_METHOD']==='POST' && !isset($_POST['login'])) {
    $action=(string)($_POST['action']??'');
    $data=loadProducts($productsFile, $dataFile); $cfg=loadConfig($configFile);

    if ($action==='change_password') {
        $current=(string)($_POST['current_password']??'');
        $new=(string)($_POST['new_password']??'');
        $confirm=(string)($_POST['confirm_password']??'');
        if (!password_verify($current, getAdminPasswordHash($adminPasswordFile))) $error='Current password is incorrect.';
        elseif (!validAdminPassword($new)) $error='New password must be at least 6 characters.';
        elseif ($new !== $confirm) $error='New password and confirmation do not match.';
        else {
            $hash=password_hash($new, PASSWORD_DEFAULT);
            if (saveAdminPasswordHash($adminPasswordFile,$hash)) { logActivity($activityFile,'Admin password changed'); $success='Admin password changed successfully. Please use the new password next time you log in.'; }
            else $error='Could not save the new password. Check file permissions.';
        }
    }
    if ($action==='save_payment') {
        $oldUpi=(string)($cfg['upi']??''); $oldMerchant=(string)($cfg['merchant_name']??'');
        $newUpi=trim((string)($_POST['upi']??'')); $newMerchant=trim((string)($_POST['merchant_name']??''));
        if (!validUpi($newUpi)) $error='Invalid UPI ID. Example: name@upi';
        elseif ($newMerchant==='' || strlen($newMerchant)>100) $error='Enter a valid merchant name.';
        else { $cfg['upi']=$newUpi; $cfg['merchant_name']=$newMerchant; if (!saveConfig($configFile,$cfg)) $error='Cannot write webinfo.json. Check permissions.'; else { replaceInFiles($root,$oldUpi,$newUpi,$oldMerchant,$newMerchant); logActivity($activityFile,'Payment settings updated'); $success='Payment settings saved.'; } }
    }
    if ($action==='save_payment_modes') {
        $mode=(string)($_POST['payment_mode']??'both');
        $provider=(string)($_POST['auto_provider']??'paytm');
        $paytmMid=trim((string)($_POST['paytm_mid']??''));
        $bharatpeId=trim((string)($_POST['bharatpe_merchant_id']??''));
        $manualEnabled=isset($_POST['manual_upi_enabled']);
        if (!in_array($mode,['both','manual','auto'],true)) $error='Invalid payment mode.';
        elseif (!in_array($provider,['paytm','bharatpe'],true)) $error='Invalid auto provider.';
        elseif (strlen($paytmMid)>100 || strlen($bharatpeId)>100) $error='Merchant ID is too long.';
        else {
            $cfg['payment_mode']=$mode; $cfg['manual_upi_enabled']=$manualEnabled;
            $cfg['auto_provider']=$provider; $cfg['paytm_mid']=$paytmMid; $cfg['bharatpe_merchant_id']=$bharatpeId;
            // Static merchant QR / UPI ID cannot securely confirm a payment.
            $cfg['auto_payment_enabled']=false;
            if (saveConfig($configFile,$cfg)) {
                logActivity($activityFile,'Payment mode settings updated');
                $success='Payment mode settings saved. Auto verification stays inactive until official API/webhook integration is installed.';
            } else $error='Could not save payment mode settings.';
        }
    }
    if ($action==='save_store') {
        $cfg['store_name']=trim((string)($_POST['store_name']??'')); $cfg['support']=trim((string)($_POST['support']??''));
        $cfg['announcement']=trim((string)($_POST['announcement']??'')); $cfg['maintenance']=isset($_POST['maintenance']);
        if ($cfg['store_name']==='') $error='Store name is required.';
        elseif (saveConfig($configFile,$cfg)) { logActivity($activityFile,'Store settings updated'); $success='Store settings saved.'; }
        else $error='Could not save store settings.';
    }
    if ($action==='save_product') {
        $id=trim((string)($_POST['id']??'')); $categorySlug=trim((string)($_POST['category']??'')); $name=trim((string)($_POST['name']??''));
        $amount=trim((string)($_POST['amount']??'')); $sub=trim((string)($_POST['sub']??'')); $icon=trim((string)($_POST['icon']??'💎')); $image=trim((string)($_POST['image']??''));
        $price=max(0,(float)($_POST['price']??0)); $old=max(0,(float)($_POST['old']??0)); $tag=trim((string)($_POST['tag']??'')); $stock=max(0,(int)($_POST['stock']??0));
        if ($id===''||$categorySlug===''||$name==='') $error='Product ID, category and product name are required.';
        else {
            $found=false;
            foreach ($data['categories'] as &$cat) {
                if (($cat['slug']??'')!==$categorySlug) continue; $found=true;
                $product=['id'=>$id,'icon'=>$icon?:'💎','image'=>$image,'amount'=>$amount,'name'=>$name,'sub'=>$sub,'price'=>$price,'old'=>$old,'tag'=>($tag===''?null:$tag),'stock'=>$stock,'inStock'=>($stock>0)];
                $replaced=false; foreach (($cat['items']??[]) as $k=>$item) if (($item['id']??'')===$id) { $cat['items'][$k]=$product; $replaced=true; break; }
                if(!$replaced) $cat['items'][]=$product; break;
            } unset($cat);
            if(!$found) $error='Category not found.'; elseif(saveProducts($productsFile,$data,$dataFile)) { logActivity($activityFile,"Product saved: $id"); $success='Product saved successfully.'; } else $error='Could not write product list.';
        }
    }
    if ($action==='delete_product') {
        $id=trim((string)($_POST['id']??'')); $deleted=false;
        foreach ($data['categories'] as &$cat) foreach (($cat['items']??[]) as $k=>$item) if (($item['id']??'')===$id) { array_splice($cat['items'],$k,1); $deleted=true; break 2; }
        unset($cat); if($deleted&&saveProducts($productsFile,$data,$dataFile)){logActivity($activityFile,"Product deleted: $id");$success='Product deleted.';}else $error=$deleted?'Could not save product list.':'Product not found.';
    }
    if ($action==='save_tag') {
        $id=trim((string)($_POST['id']??''));
        $tag=preg_replace('/\s+/u',' ',trim((string)($_POST['tag']??'')));
        if (textLen($tag)>32) $tag=textSub($tag,0,32);
        $updated=false;
        foreach ($data['categories'] as &$cat) {
            foreach ($cat['items'] as &$item) {
                if ((string)($item['id']??'') !== $id) continue;
                $item['tag']=$tag===''?null:$tag;
                $updated=true;
                break 2;
            }
        }
        unset($cat,$item);
        if (!$updated) {
            $error='Product not found.';
        } else {
            // The override file is the canonical tag store. It is deliberately kept
            // separate from products_data.json so a tag update can never be lost when
            // another part of the panel rewrites the product database.
            $overrideFile=$root.'/tag_overrides.json';
            $overrides=[];
            if (is_file($overrideFile)) {
                $j=json_decode((string)@file_get_contents($overrideFile),true);
                if (is_array($j)) $overrides=$j;
            }
            // Keep an explicit null entry for Clear so an old tag in products_data.json
            // cannot reappear after reload.
            $overrides[$id]=($tag===''?null:$tag);
            $saved=@file_put_contents($overrideFile,json_encode($overrides,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n",LOCK_EX);
            if ($saved!==false) {
                // Best-effort sync to the main product data too; failure here must not
                // invalidate the tag because the override is already persistent.
                saveProducts($productsFile,$data,$dataFile);
                logActivity($activityFile,"Tag updated: $id → ".($tag?:'none'));
                $success=$tag===''?'Product tag removed successfully.':'Product tag updated successfully.';
            } else {
                $error='Could not save tag. Please make sure the website root is writable.';
            }
        }
    }
    if ($action==='save_stock') {
        $id=trim((string)($_POST['id']??'')); $stock=max(0,(int)($_POST['stock']??0)); $updated=false;
        foreach ($data['categories'] as &$cat) foreach ($cat['items'] as &$item) if (($item['id']??'')===$id) { $item['stock']=$stock;$item['inStock']=$stock>0;$updated=true;break 2; }
        unset($cat,$item); if($updated&&saveProducts($productsFile,$data,$dataFile)){logActivity($activityFile,"Stock updated: $id → $stock");$success='Stock updated.';}else $error=$updated?'Could not save stock.':'Product not found.';
    }
    if ($action==='update_order') {
        $oid=trim((string)($_POST['order_id']??''));$st=(string)($_POST['status']??'');$allowed=['paid','rejected','processing','delivered'];$path=$root.'/orders.json';$all=is_file($path)?json_decode((string)file_get_contents($path),true):[];if(!is_array($all))$all=[];$changed=false;
        foreach($all as &$o)if(($o['order_id']??'')===$oid&&in_array($st,$allowed,true)){$o['status']=$st;$o['updated_at']=date('c');$o['verified_by']='admin';$changed=true;break;}unset($o);
        if($changed&&file_put_contents($path,json_encode(array_values($all),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n",LOCK_EX)!==false){logActivity($activityFile,"Order $oid set to $st");$success="Order $oid updated.";}else $error='Order not found or update failed.';
    }
    if ($action==='bulk_stock') {
        $mode=(string)($_POST['mode']??'set'); $value=max(0,(int)($_POST['value']??0)); $ids=$_POST['ids']??[]; if(!is_array($ids))$ids=[]; $changed=0;
        foreach ($data['categories'] as &$cat) foreach ($cat['items'] as &$item) if(in_array(($item['id']??''),$ids,true)) { $cur=(int)($item['stock']??0); $new=$mode==='add'?max(0,$cur+$value):($mode==='sub'?max(0,$cur-$value):$value); $item['stock']=$new;$item['inStock']=$new>0;$changed++; }
        unset($cat,$item); if($changed&&saveProducts($productsFile,$data,$dataFile)){logActivity($activityFile,"Bulk stock update: $changed products");$success="$changed products updated.";}else $error='Select at least one product.';
    }
    $data=loadProducts($productsFile, $dataFile); $cfg=loadConfig($configFile);
}

$cfg=loadConfig($configFile); $data=loadProducts($productsFile, $dataFile); $activity=loadActivity($activityFile); $logged=!empty($_SESSION['admin_auth']);
$products=[];$categories=[];
foreach (($data['categories']??[]) as $cat) { $categories[]=$cat; foreach(($cat['items']??[]) as $item){$item['_category']=$cat['slug']??'';$item['_category_name']=$cat['name']??($cat['slug']??'');$products[]=$item;} }
$total=count($products); $inStock=count(array_filter($products,fn($x)=>(int)($x['stock']??0)>0)); $outStock=$total-$inStock; $lowStock=count(array_filter($products,fn($x)=>((int)($x['stock']??0)>0&&(int)($x['stock']??0)<=10))); $tagged=count(array_filter($products,fn($x)=>!empty($x['tag']))); $catCount=count($categories);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($cfg['store_name'])?> — Admin</title>
<style>
:root{--bg:#f4f7fb;--panel:#fff;--text:#142033;--muted:#718096;--line:#e4e9f1;--primary:#2563eb;--primary2:#1d4ed8;--soft:#edf4ff;--green:#11864b;--greenbg:#e9f9f0;--red:#c53030;--redbg:#fff0f0;--amber:#9a6700;--amberbg:#fff6d8;--shadow:0 18px 55px rgba(18,32,56,.09);--sidebar:#0d1728}*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:linear-gradient(180deg,#f8fafc,#eef3f9);font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;color:var(--text)}button,input,select,textarea{font:inherit}.app{min-height:100vh;display:flex}.sidebar{width:245px;background:var(--sidebar);color:#fff;position:fixed;inset:0 auto 0 0;padding:20px 14px;display:flex;flex-direction:column;z-index:30}.brand{display:flex;align-items:center;gap:11px;padding:5px 8px 22px}.brand-logo{width:43px;height:43px;border-radius:14px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);display:grid;place-items:center;font-size:21px;box-shadow:0 10px 24px rgba(59,130,246,.28)}.brand b{font-size:16px}.brand small{display:block;color:#91a0b8;margin-top:2px}.nav{display:grid;gap:7px}.nav button{border:0;background:transparent;color:#aebbd0;text-align:left;padding:12px 13px;border-radius:13px;cursor:pointer;font-weight:800}.nav button:hover,.nav button.active{background:rgba(255,255,255,.1);color:#fff}.sidebar-foot{margin-top:auto;border-top:1px solid rgba(255,255,255,.08);padding-top:15px}.sidebar-foot a{color:#aebbd0;text-decoration:none;font-size:13px}.main{margin-left:245px;width:calc(100% - 245px);padding:22px 24px 80px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:20px}.eyebrow{color:var(--primary);font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.12em}.title{font-size:28px;font-weight:950;letter-spacing:-.7px;margin:3px 0}.subtitle{margin:0;color:var(--muted);font-size:13px}.top-actions{display:flex;gap:8px}.theme-btn,.logout{border:1px solid var(--line);background:#fff;color:var(--text);padding:10px 13px;border-radius:12px;text-decoration:none;font-weight:800;cursor:pointer}.logout{color:#b42318}.page{display:none}.page.active{display:block}.card{background:var(--panel);border:1px solid rgba(228,233,241,.9);border-radius:20px;padding:20px;box-shadow:var(--shadow);margin-bottom:17px}.hero{background:linear-gradient(135deg,#0f1c32,#1d4ed8);color:#fff;border:0;overflow:hidden;position:relative}.hero:after{content:"";position:absolute;width:250px;height:250px;border-radius:50%;right:-80px;top:-100px;background:rgba(255,255,255,.08)}.hero h2{margin:0 0 6px;font-size:23px}.hero p{margin:0;color:#dbe7ff}.hero-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}.hero-actions button{border:0;border-radius:11px;padding:10px 13px;font-weight:900;cursor:pointer}.hero-actions .light{background:#fff;color:#17305f}.hero-actions .ghost{background:rgba(255,255,255,.1);color:#fff;border:1px solid rgba(255,255,255,.2)}.stats{display:grid;grid-template-columns:repeat(6,1fr);gap:12px}.stat{padding:16px;border:1px solid var(--line);border-radius:16px;background:#fbfcff}.stat b{display:block;font-size:25px}.stat span{color:var(--muted);font-size:12px;font-weight:700}.stat.warn b{color:var(--amber)}.stat.danger b{color:var(--red)}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.field{margin-bottom:12px}.field label{display:block;font-size:12px;font-weight:900;margin-bottom:6px}.field input,.field select,.field textarea{width:100%;padding:11px 12px;border:1px solid #d8dfeb;border-radius:11px;background:#fff;color:var(--text);outline:none}.field textarea{min-height:90px;resize:vertical}.field input:focus,.field select:focus,.field textarea:focus{border-color:#8db2ff;box-shadow:0 0 0 3px #edf4ff}.btn{border:0;background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;padding:10px 13px;border-radius:11px;font-weight:900;cursor:pointer}.btn.gray{background:#eef2f7;color:#233044}.btn.soft{background:var(--soft);color:#1d4ed8}.btn.danger{background:#fff0f0;color:#b42318}.btn.full{width:100%}.section-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}.pill{display:inline-flex;align-items:center;background:var(--soft);color:#1d4ed8;border-radius:999px;padding:7px 10px;font-size:11px;font-weight:950}.toolbar{display:flex;gap:10px;align-items:center;justify-content:space-between;margin:16px 0}.search{flex:1;max-width:520px}.search input{width:100%;padding:11px 13px;border:1px solid var(--line);border-radius:12px}.filters{display:flex;gap:7px;flex-wrap:wrap}.filter{border:1px solid var(--line);background:#fff;border-radius:999px;padding:8px 11px;font-size:11px;font-weight:900;cursor:pointer}.filter.active{background:#eaf1ff;color:#1d4ed8;border-color:#cfe0ff}.product-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.product{border:1px solid var(--line);border-radius:17px;padding:14px;background:linear-gradient(180deg,#fff,#fbfcfe)}.prod-head{display:flex;gap:11px;align-items:center}.prod-icon{width:52px;height:52px;border-radius:14px;background:#eef3fb;display:grid;place-items:center;font-size:24px;overflow:hidden;flex:0 0 52px}.prod-icon img{width:100%;height:100%;object-fit:cover}.prod-name{font-weight:950}.meta{font-size:11px;color:var(--muted);margin-top:4px}.badges{display:flex;gap:6px;flex-wrap:wrap;margin:10px 0}.badge{padding:6px 9px;border-radius:999px;background:#eef3fb;font-size:10px;font-weight:900}.stock{background:var(--greenbg);color:var(--green)}.nostock{background:var(--redbg);color:var(--red)}.low{background:var(--amberbg);color:var(--amber)}.tag-badge{background:#fff4d6;color:#8a5a00}.actions{display:grid;grid-template-columns:1fr 1fr;gap:7px}.actions form{margin:0}.tag-editor{margin-top:11px;padding-top:11px;border-top:1px dashed #dfe5ee}.tag-row{display:flex;gap:7px}.tag-row input{flex:1;min-width:0;padding:10px;border:1px solid #d8deea;border-radius:10px}.quick-tags{display:flex;gap:5px;flex-wrap:wrap;margin-top:7px}.quick-tag{border:1px solid #e0e5ed;background:#f8fafc;border-radius:999px;padding:5px 8px;font-size:10px;font-weight:900;cursor:pointer}.notice{padding:11px;border-radius:11px;background:#f4f7fb;word-break:break-all}.small{font-size:12px;color:var(--muted)}.msg{padding:11px 13px;border-radius:11px;margin:12px 0;font-weight:800;font-size:13px}.msg.ok{background:var(--greenbg);color:var(--green)}.msg.err{background:var(--redbg);color:var(--red)}.login-wrap{max-width:440px;margin:80px auto}.activity{display:grid;gap:9px}.activity-item{display:flex;gap:10px;align-items:flex-start;padding:10px;border:1px solid var(--line);border-radius:12px}.activity-dot{width:8px;height:8px;border-radius:50%;background:var(--primary);margin-top:5px;flex:0 0 8px}.activity-item b{font-size:12px}.activity-item time{display:block;color:var(--muted);font-size:10px;margin-top:2px}.mobile-nav{display:none}.dark{--bg:#0b1220;--panel:#111a2a;--text:#eef4ff;--muted:#91a0b8;--line:#263247;--shadow:0 18px 55px rgba(0,0,0,.25)}.dark body{background:#0b1220}.dark .card,.dark .stat,.dark .product,.dark .theme-btn,.dark .logout,.dark .filter{background:#111a2a;color:var(--text);border-color:#263247}.dark .field input,.dark .field select,.dark .field textarea,.dark .search input,.dark .tag-row input{background:#0d1626;color:#fff;border-color:#263247}.dark .btn.gray,.dark .quick-tag{background:#1b2639;color:#eaf1ff;border-color:#2b3950}.dark .notice{background:#0d1626}.dark .hero{background:linear-gradient(135deg,#0d1626,#173a92)}
@media(max-width:1050px){.stats{grid-template-columns:repeat(3,1fr)}.product-list{grid-template-columns:1fr}}
@media(max-width:760px){.sidebar{display:none}.main{margin-left:0;width:100%;padding:12px 10px 92px}.topbar{align-items:flex-start}.title{font-size:22px}.top-actions .logout{display:none}.card{padding:15px;border-radius:17px}.stats{grid-template-columns:repeat(2,1fr)}.grid{grid-template-columns:1fr}.toolbar{align-items:stretch;flex-direction:column}.search{max-width:none;width:100%}.tag-row{flex-direction:column}.tag-row .btn{width:100%}.mobile-nav{display:grid;grid-template-columns:repeat(6,1fr);position:fixed;left:8px;right:8px;bottom:8px;background:#101a2c;color:#9eacc0;border:1px solid rgba(255,255,255,.1);border-radius:18px;padding:7px;z-index:40;box-shadow:0 12px 35px rgba(0,0,0,.25)}.mobile-nav button{border:0;background:transparent;color:inherit;padding:8px 3px;font-size:10px;font-weight:900;border-radius:12px}.mobile-nav button.active{background:rgba(255,255,255,.1);color:#fff}.hero-actions button{flex:1;min-width:130px}.section-head{align-items:center}}
</style></head><body>
<?php if(!$logged): ?><div class="login-wrap"><div class="card"><div class="brand"><div class="brand-logo">⚡</div><div><b>Store Control</b><small>Admin Dashboard</small></div></div><h2>🔐 Admin Login</h2><p class="small">Secure control panel for your store.</p><?php if($error):?><div class="msg err"><?=h($error)?></div><?php endif;?><form method="post"><div class="field"><label>Username</label><input name="username" autocomplete="username" required></div><div class="field"><label>Password</label><input type="password" name="password" autocomplete="current-password" required></div><button class="btn full" name="login" value="1">Login to Dashboard</button></form></div></div><?php else: ?>
<div class="app">
<aside class="sidebar"><div class="brand"><div class="brand-logo">⚡</div><div><b><?=h($cfg['store_name'])?></b><small>Control Center</small></div></div><nav class="nav"><button class="active" data-page="dashboard" onclick="showPage('dashboard',this)">📊 Dashboard</button><button data-page="products" onclick="showPage('products',this)">🛍️ Products</button><button data-page="stock" onclick="showPage('stock',this)">📦 Stock</button><button data-page="orders" onclick="showPage('orders',this)">Orders</button><button data-page="payment" onclick="showPage('payment',this)">💳 Payments</button><button data-page="settings" onclick="showPage('settings',this)">⚙️ Settings</button></nav><div class="sidebar-foot"><a href="?logout=1">↪ Logout</a></div></aside>
<main class="main"><div class="topbar"><div><div class="eyebrow">Admin Control Center</div><div class="title" id="pageTitle">Dashboard</div><p class="subtitle">Manage your store from one mobile-friendly panel.</p></div><div class="top-actions"><button class="theme-btn" onclick="toggleTheme()">◐ Theme</button><a class="logout" href="?logout=1">Logout</a></div></div>
<?php if($error):?><div class="msg err">⚠️ <?=h($error)?></div><?php endif;?><?php if($success):?><div class="msg ok">✅ <?=h($success)?></div><?php endif;?>

<section id="dashboard" class="page active"><div class="card hero"><h2>Welcome back, Admin 👋</h2><p><?=h($cfg['store_name'])?> is ready to manage. Use Quick Actions to jump straight into a task.</p><div class="hero-actions"><button class="light" onclick="showPage('products',document.querySelector('[data-page=products]'))">＋ Add Product</button><button class="light" onclick="showPage('stock',document.querySelector('[data-page=stock]'))">📦 Update Stock</button><button class="ghost" onclick="showPage('payment',document.querySelector('[data-page=payment]'))">💳 Payment Settings</button></div></div>
<div class="card"><div class="section-head"><div><h2>📊 Store Overview</h2><p class="small">Live totals from your product database.</p></div><span class="pill"><?=h($catCount)?> CATEGORIES</span></div><div class="stats"><div class="stat"><b><?=$total?></b><span>Total Products</span></div><div class="stat"><b><?=$inStock?></b><span>In Stock</span></div><div class="stat danger"><b><?=$outStock?></b><span>Out of Stock</span></div><div class="stat warn"><b><?=$lowStock?></b><span>Low Stock ≤ 10</span></div><div class="stat"><b><?=$tagged?></b><span>Tagged Products</span></div></div></div>
<div class="grid"><div class="card"><div class="section-head"><div><h2>⚠️ Inventory Alerts</h2><p class="small">Products needing attention.</p></div></div><?php $alerts=array_filter($products,fn($p)=>(int)($p['stock']??0)<=10); if(!$alerts):?><div class="notice">✅ No low/out-of-stock products.</div><?php else:?><div class="activity"><?php foreach(array_slice($alerts,0,8) as $p):?><div class="activity-item"><div class="activity-dot"></div><div><b><?=h($p['name'])?></b><div class="small">Stock: <?=h($p['stock']??0)?> · <?=h($p['_category_name'])?></div></div></div><?php endforeach;?></div><?php endif;?></div>
<div class="card"><div class="section-head"><div><h2>🕘 Admin Activity</h2><p class="small">Recent changes made in this panel.</p></div></div><?php if(!$activity):?><div class="notice">No activity yet.</div><?php else:?><div class="activity"><?php foreach(array_slice($activity,0,7) as $a):?><div class="activity-item"><div class="activity-dot"></div><div><b><?=h($a['action']??'Activity')?></b><time><?=h($a['time']??'')?></time></div></div><?php endforeach;?></div><?php endif;?></div></div></section>

<section id="products" class="page"><div class="card"><div class="section-head"><div><h2>🛍️ Product Manager</h2><p class="small">Create, edit, delete and tag products.</p></div><span class="pill"><?=$total?> PRODUCTS</span></div><form method="post" id="productForm"><input type="hidden" name="action" value="save_product"><div class="grid"><div class="field"><label>Product ID</label><input name="id" id="pid" placeholder="p19" required></div><div class="field"><label>Category</label><select name="category" id="pcat" required><?php foreach($categories as $c):?><option value="<?=h($c['slug'])?>"><?=h($c['name']??$c['slug'])?></option><?php endforeach;?></select></div><div class="field"><label>Product Name</label><input name="name" id="pname" required></div><div class="field"><label>Amount</label><input name="amount" id="pamount" placeholder="1060"></div><div class="field"><label>Subtitle</label><input name="sub" id="psub" placeholder="+106 bonus"></div><div class="field"><label>Icon</label><input name="icon" id="picon" value="💎"></div><div class="field"><label>Image path / URL</label><input name="image" id="pimage" placeholder="uploads/products/example.png"></div><div class="field"><label>Selling Price ₹</label><input type="number" step="0.01" min="0" name="price" id="pprice" required></div><div class="field"><label>Old Price ₹</label><input type="number" step="0.01" min="0" name="old" id="pold"></div><div class="field"><label>Tag</label><select name="tag" id="ptag"><option value="">None</option><option value="🔥 HOT">🔥 HOT</option><option value="⭐ BEST">⭐ BEST</option><option value="🆕 NEW">🆕 NEW</option><option value="⚡ LIMITED">⚡ LIMITED</option></select></div><div class="field"><label>Initial Stock</label><input type="number" min="0" name="stock" id="pstock" value="0"></div></div><button class="btn" type="submit">💾 Save Product</button> <button class="btn gray" type="button" onclick="resetForm()">Clear</button></form></div>
<div class="card"><div class="toolbar"><div class="search"><input id="productSearch" placeholder="🔎 Search product, ID or category..." oninput="filterProducts()"></div><div class="filters"><button class="filter active" onclick="filterTag('all',this)">All</button><button class="filter" onclick="filterTag('tagged',this)">🏷️ Tagged</button><button class="filter" onclick="filterTag('stock',this)">📦 In Stock</button><button class="filter" onclick="filterTag('out',this)">🚫 Out</button></div></div><div class="product-list" id="productList"><?php foreach($products as $p):$stk=(int)($p['stock']??0);?><div class="product" data-search="<?=h(strtolower(($p['name']??'').' '.($p['id']??'').' '.($p['_category_name']??'')))?>" data-tagged="<?=!empty($p['tag'])?'1':'0'?>" data-stock="<?=$stk?>"><div class="prod-head"><div class="prod-icon"><?php if(!empty($p['image'])):?><img src="<?=h($p['image'])?>" onerror="this.style.display='none'" alt=""><?php else:?><?=h($p['icon']??'💎')?><?php endif;?></div><div><div class="prod-name"><?=h($p['name']??'')?></div><div class="meta">ID: <?=h($p['id']??'')?> · <?=h($p['_category_name'])?></div></div></div><div class="badges"><span class="badge">₹<?=h($p['price']??0)?></span><span class="badge <?=($stk===0?'nostock':($stk<=10?'low':'stock'))?>">Stock: <?=$stk?></span><?php if(!empty($p['tag'])):?><span class="badge tag-badge">🏷️ <?=h($p['tag'])?></span><?php endif;?></div><div class="tag-editor"><div class="small" style="margin-bottom:7px;font-weight:900">🏷️ Product Tag</div><form method="post" class="tag-row"><input type="hidden" name="action" value="save_tag"><input type="hidden" name="id" value="<?=h($p['id'])?>"><input name="tag" maxlength="32" value="<?=h($p['tag']??'')?>" placeholder="HOT / BEST / NEW / LIMITED"><button class="btn soft" type="submit">Save Tag</button></form><div class="quick-tags"><button type="button" class="quick-tag" onclick="setTag(this,'🔥 HOT')">🔥 HOT</button><button type="button" class="quick-tag" onclick="setTag(this,'⭐ BEST')">⭐ BEST</button><button type="button" class="quick-tag" onclick="setTag(this,'🆕 NEW')">🆕 NEW</button><button type="button" class="quick-tag" onclick="setTag(this,'⚡ LIMITED')">⚡ LIMITED</button><button type="button" class="quick-tag" onclick="setTag(this,'')">✕ Clear</button></div></div><div class="actions"><button class="btn gray" type="button" onclick='editProduct(<?=json_encode($p,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>)'>✏️ Edit</button><form method="post" onsubmit="return confirm('Delete this product?')"><input type="hidden" name="action" value="delete_product"><input type="hidden" name="id" value="<?=h($p['id'])?>"><button class="btn danger full">🗑️ Delete</button></form></div></div><?php endforeach;?></div></div></section>

<section id="stock" class="page">
<div class="card">
<div class="section-head"><div><h2>📦 Advanced Stock Manager</h2><p class="small">Quick + / − controls, low-stock filters and bulk updates.</p></div></div>
<form method="post" id="bulkForm" class="toolbar">
<input type="hidden" name="action" value="bulk_stock">
<div class="filters"><select name="mode" class="filter"><option value="set">Set stock to</option><option value="add">Add stock</option><option value="sub">Remove stock</option></select><input type="number" name="value" min="0" value="10" class="filter" style="width:100px"></div>
<button class="btn" type="submit" onclick="return collectSelected()">Apply to selected</button>
</form>
<div class="toolbar"><div class="search"><input id="stockSearch" placeholder="🔎 Search stock..." oninput="filterStock()"></div><div class="filters"><button type="button" class="filter active" onclick="filterStockType('all',this)">All</button><button type="button" class="filter" onclick="filterStockType('low',this)">⚠️ Low</button><button type="button" class="filter" onclick="filterStockType('out',this)">🚫 Out</button></div></div>
<div class="product-list" id="stockList">
<?php foreach($products as $p) { $stk=(int)($p['stock']??0); ?>
<div class="product" data-stock-search="<?=h(strtolower(($p['name']??'').' '.($p['id']??'').' '.($p['_category_name']??'')))?>" data-stock-value="<?=$stk?>">
<div class="prod-head"><input type="checkbox" name="ids[]" value="<?=h($p['id'])?>" form="bulkForm" style="width:18px;height:18px"><div class="prod-icon"><?=h($p['icon']??'💎')?></div><div><div class="prod-name"><?=h($p['name'])?></div><div class="meta"><?=h($p['id'])?> · <?=h($p['_category_name'])?></div></div></div>
<div class="badges"><span class="badge <?=($stk===0?'nostock':($stk<=10?'low':'stock'))?>">Current: <?=$stk?></span></div>
<div class="actions">
<form method="post"><input type="hidden" name="action" value="save_stock"><input type="hidden" name="id" value="<?=h($p['id'])?>"><input type="hidden" name="stock" value="<?=max(0,$stk-1)?>"><button class="btn gray full">− 1</button></form>
<form method="post"><input type="hidden" name="action" value="save_stock"><input type="hidden" name="id" value="<?=h($p['id'])?>"><input type="hidden" name="stock" value="<?=$stk+1?>"><button class="btn soft full">＋ 1</button></form>
</div>
<form method="post" style="margin-top:8px"><input type="hidden" name="action" value="save_stock"><input type="hidden" name="id" value="<?=h($p['id'])?>"><div class="field"><label>Set exact stock</label><input type="number" name="stock" min="0" value="<?=$stk?>"></div><button class="btn full">💾 Update Stock</button></form>
</div>
<?php } ?>
</div></div></section>

<section id="orders" class="page"><div class="card"><h2>Orders & Payment Verification</h2><p class="small">Check UTR in your merchant bank / UPI statement before marking Paid.</p><?php $op=$root.'/orders.json';$all=is_file($op)?json_decode((string)file_get_contents($op),true):[];if(!is_array($all))$all=[];$all=array_reverse($all);if(!$all):?><div class="notice">No orders yet.</div><?php else:?><div style="overflow-x:auto"><table style="width:100%;min-width:700px"><tr><th>Order</th><th>UID / Player</th><th>Product</th><th>Amount</th><th>UTR / Status</th><th>Action</th></tr><?php foreach($all as $o):?><tr><td><?=h($o['order_id']??'')?></td><td><?=h($o['uid']??'')?> / <?=h($o['nickname']??'')?></td><td><?=h($o['product_name']??'')?></td><td>Rs <?=h(number_format((float)($o['amount']??0),2))?></td><td><?=h($o['utr']?:'Not submitted')?> / <?=h($o['status']??'')?></td><td><form method="post"><input type="hidden" name="action" value="update_order"><input type="hidden" name="order_id" value="<?=h($o['order_id']??'')?>"><button class="btn" name="status" value="paid" onclick="return confirm('Verified in merchant account?')">Verify Paid</button><button class="btn danger" name="status" value="rejected">Reject</button><button class="btn gray" name="status" value="processing">Processing</button><button class="btn gray" name="status" value="delivered">Delivered</button></form></td></tr><?php endforeach;?></table></div><?php endif;?></div></section>
<section id="payment" class="page">
<div class="card"><h2>💳 Payment Settings</h2><p class="small">Normal UPI / QR settings used by manual payment flow.</p><div class="field"><label>Current UPI</label><div class="notice"><?=h($cfg['upi']??'Not set')?></div></div><form method="post"><input type="hidden" name="action" value="save_payment"><div class="field"><label>UPI ID</label><input name="upi" value="<?=h($cfg['upi']??'')?>" placeholder="example@upi" required></div><div class="field"><label>Merchant Name</label><input name="merchant_name" value="<?=h($cfg['merchant_name']??'')?>" required></div><button class="btn full">💾 Save UPI Settings</button></form></div>
<div class="card"><h2>⚙️ Payment Verification Modes</h2><p class="small">Select which checkout mode should be offered to customers.</p>
<form method="post"><input type="hidden" name="action" value="save_payment_modes">
<div class="field"><label>Payment Mode</label><select name="payment_mode"><option value="both" <?=($cfg['payment_mode']??'both')==='both'?'selected':''?>>Both: Auto + Normal UPI</option><option value="manual" <?=($cfg['payment_mode']??'')==='manual'?'selected':''?>>Normal UPI only</option><option value="auto" <?=($cfg['payment_mode']??'')==='auto'?'selected':''?>>Auto verification only (requires API)</option></select></div>
<div class="field"><label>Automatic provider</label><select name="auto_provider"><option value="paytm" <?=($cfg['auto_provider']??'paytm')==='paytm'?'selected':''?>>Paytm</option><option value="bharatpe" <?=($cfg['auto_provider']??'')==='bharatpe'?'selected':''?>>BharatPe</option></select></div>
<div class="field"><label>Paytm Merchant ID (MID)</label><input name="paytm_mid" value="<?=h($cfg['paytm_mid']??'')?>" placeholder="Enter MID if issued"></div>
<div class="field"><label>BharatPe Merchant ID</label><input name="bharatpe_merchant_id" value="<?=h($cfg['bharatpe_merchant_id']??'')?>" placeholder="Enter Merchant ID if API access issued"></div>
<div class="field"><label style="display:flex;align-items:center;gap:9px"><input type="checkbox" name="manual_upi_enabled" value="1" <?=!empty($cfg['manual_upi_enabled'])?'checked':''?> style="width:18px;height:18px"> Enable Normal UPI / UTR review</label></div>
<div class="notice" style="margin:12px 0">⚠️ Auto verification is NOT active yet. Merchant QR / UPI ID / Merchant ID alone cannot securely confirm a payment. Official API access, server-side transaction verification and signed webhook validation are required. Orders will not be marked Paid automatically in this build.</div>
<button class="btn full">💾 Save Payment Mode</button></form></div></section>

<section id="settings" class="page"><div class="card"><h2>⚙️ Store Settings</h2><p class="small">Branding, support and store availability.</p><form method="post"><input type="hidden" name="action" value="save_store"><div class="field"><label>Store Name</label><input name="store_name" value="<?=h($cfg['store_name']??'')?>" required></div><div class="field"><label>Support Link / Contact</label><input name="support" value="<?=h($cfg['support']??'')?>" placeholder="https://t.me/your_support"></div><div class="field"><label>Announcement Banner</label><textarea name="announcement" placeholder="Example: New stock available today!"> <?=h($cfg['announcement']??'')?></textarea></div><div class="field"><label style="display:flex;align-items:center;gap:9px"><input type="checkbox" name="maintenance" value="1" <?=!empty($cfg['maintenance'])?'checked':''?> style="width:18px;height:18px"> Maintenance Mode</label></div><button class="btn full">💾 Save Store Settings</button></form></div><div class="card"><h2>🔐 Admin Security</h2><p class="small">Change your admin panel password securely. Minimum 6 characters.</p><form method="post"><input type="hidden" name="action" value="change_password"><div class="field"><label>Current Password</label><input type="password" name="current_password" autocomplete="current-password" required></div><div class="field"><label>New Password</label><input type="password" name="new_password" minlength="6" autocomplete="new-password" required><div class="small">Minimum 6 characters.</div></div><div class="field"><label>Confirm New Password</label><input type="password" name="confirm_password" minlength="6" autocomplete="new-password" required></div><button class="btn full" type="submit">🔒 Change Admin Password</button></form><div class="notice" style="margin-top:12px">Username: <b>admin</b></div></div></section>
</main></div><div class="mobile-nav"><button class="active" data-page="dashboard" onclick="showPage('dashboard',this)">📊<br>Home</button><button data-page="products" onclick="showPage('products',this)">🛍️<br>Products</button><button data-page="stock" onclick="showPage('stock',this)">📦<br>Stock</button><button data-page="payment" onclick="showPage('payment',this)">💳<br>Pay</button><button data-page="settings" onclick="showPage('settings',this)">⚙️<br>Settings</button></div>
<?php endif; ?>
<script>
function showPage(id,btn){document.querySelectorAll('.page').forEach(x=>x.classList.remove('active'));document.getElementById(id).classList.add('active');document.querySelectorAll('[data-page]').forEach(x=>x.classList.toggle('active',x.dataset.page===id));document.getElementById('pageTitle').textContent={dashboard:'Dashboard',products:'Product Manager',stock:'Stock Manager',payment:'Payment Settings',settings:'Store Settings'}[id]||'Dashboard';window.scrollTo({top:0,behavior:'smooth'});}
function toggleTheme(){document.documentElement.classList.toggle('dark');localStorage.setItem('admin_theme',document.documentElement.classList.contains('dark')?'dark':'light');}
try{if(localStorage.getItem('admin_theme')==='dark')document.documentElement.classList.add('dark')}catch(e){}
function editProduct(p){showPage('products',document.querySelector('[data-page=products]'));document.getElementById('pid').value=p.id||'';document.getElementById('pcat').value=p._category||'';document.getElementById('pname').value=p.name||'';document.getElementById('pamount').value=p.amount||'';document.getElementById('psub').value=p.sub||'';document.getElementById('picon').value=p.icon||'💎';document.getElementById('pimage').value=p.image||'';document.getElementById('pprice').value=p.price||0;document.getElementById('pold').value=p.old||0;document.getElementById('ptag').value=p.tag||'';document.getElementById('pstock').value=p.stock||0;window.scrollTo({top:0,behavior:'smooth'});}
function resetForm(){document.getElementById('productForm').reset();document.getElementById('picon').value='💎';}
function setTag(btn,value){const row=btn.closest('.tag-editor');row.querySelector('input[name=tag]').value=value;}
function filterProducts(){const q=(document.getElementById('productSearch').value||'').toLowerCase().trim();document.querySelectorAll('#productList .product').forEach(c=>c.style.display=(!q||c.dataset.search.includes(q))?'':'none');}
function filterTag(type,btn){document.querySelectorAll('#products .filter').forEach(x=>x.classList.remove('active'));btn.classList.add('active');document.querySelectorAll('#productList .product').forEach(c=>{const s=+c.dataset.stock,t=c.dataset.tagged==='1';let ok=type==='all'||(type==='tagged'&&t)||(type==='stock'&&s>0)||(type==='out'&&s===0);c.style.display=ok?'':'none';});}
function filterStock(){const q=(document.getElementById('stockSearch').value||'').toLowerCase().trim();document.querySelectorAll('#stockList .product').forEach(c=>c.style.display=(!q||c.dataset.stockSearch.includes(q))?'':'none');}
function filterStockType(type,btn){document.querySelectorAll('#stock .filter').forEach(x=>x.classList.remove('active'));btn.classList.add('active');document.querySelectorAll('#stockList .product').forEach(c=>{const s=+c.dataset.stockValue;let ok=type==='all'||(type==='low'&&s>0&&s<=10)||(type==='out'&&s===0);c.style.display=ok?'':'none';});}
function collectSelected(){return document.querySelectorAll('#bulkForm input[name="ids[]"]:checked').length>0||confirm('No products selected. Continue?');}
</script></body></html>
