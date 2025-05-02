<?php
session_start();
include("../../drop-files/lib/common.php");
include "../../drop-files/config/db.php";

if(!(isset($_SESSION['loggedin']) && $_SESSION['loggedin'] == 1)){ 
    header("location: ".SITE_URL."login.php"); 
    exit;
}

if($_SESSION['account_type'] != 5 && $_SESSION['account_type'] != 3){ 
    $_SESSION['action_error'][] = "Access Denied!";
    header("location: ".SITE_URL."admin/index.php"); 
    exit;
}

$GLOBALS['admin_template']['page_title'] = "<i class='fa fa-money'></i> Taxa Mensal Motorista"; 
$GLOBALS['admin_template']['active_menu'] = "driver-fee"; 

$monthly_fee_amount = 150.00; 

$processed_drivers = [];
$success_count = 0;
$error_count = 0;

$current_month = date('Y-m-01'); 
$next_month = date('Y-m-01', strtotime('+1 month')); 

$already_charged = [];
$query = sprintf('SELECT driver_id, firstname, lastname, last_monthly_fee_date FROM %stbl_drivers 
                 WHERE driver_status = 1 AND last_monthly_fee_date >= "%s" AND last_monthly_fee_date < "%s"', 
                 DB_TBL_PREFIX, $current_month, $next_month);
                 
if($result = mysqli_query($GLOBALS['DB'], $query)){
    while($row = mysqli_fetch_assoc($result)){
        $already_charged[$row['driver_id']] = [
            'name' => $row['firstname'] . ' ' . $row['lastname'],
            'date' => $row['last_monthly_fee_date']
        ];
    }
    mysqli_free_result($result);
}

$query_already_charged = sprintf('SELECT COUNT(*) as charged_count FROM %stbl_drivers 
WHERE driver_status = 1 AND last_monthly_fee_date >= "%s" AND last_monthly_fee_date < "%s"', 
DB_TBL_PREFIX, $current_month, $next_month);

$already_charged_count = 0;
if($result_already_charged = mysqli_query($GLOBALS['DB'], $query_already_charged)){
    if(mysqli_num_rows($result_already_charged)){
        $row = mysqli_fetch_assoc($result_already_charged);
        $already_charged_count = $row['charged_count'];
    }
    mysqli_free_result($result_already_charged);
}

if(!empty($_POST) && isset($_POST['charge_single_driver'])){
    
    if(empty($_POST['single_driver'])){
        $_SESSION['action_error'][] = "Nenhum motorista selecionado.";
        header("Location: " . htmlspecialchars($_SERVER['SCRIPT_NAME']));
        exit;
    }
    
    $driver_id = (int) $_POST['single_driver'];
    
    $force_charge = isset($_POST['force_charge']) && $_POST['force_charge'] == 1;
    
    if(!$force_charge) {
        $current_month = date('Y-m-01'); 
        $next_month = date('Y-m-01', strtotime('+1 month')); 
        
        $query = sprintf('SELECT last_monthly_fee_date FROM %stbl_drivers WHERE driver_id = %d', 
            DB_TBL_PREFIX, $driver_id);
        
        $already_charged = false;
        
        if($result = mysqli_query($GLOBALS['DB'], $query)){
            if(mysqli_num_rows($result)){
                $row = mysqli_fetch_assoc($result);
                if(!empty($row['last_monthly_fee_date'])){
                    $last_fee_date = $row['last_monthly_fee_date'];
                    if($last_fee_date >= $current_month && $last_fee_date < $next_month){
                        $already_charged = true;
                    }
                }
            }
            mysqli_free_result($result);
        }
        
        if($already_charged){
            $_SESSION['action_error'][] = "Este motorista já foi cobrado no mês atual.";
            header("Location: " . htmlspecialchars($_SERVER['SCRIPT_NAME']));
            exit;
        }
    }
    
    if(isset($_POST['single_fee_amount']) && is_numeric($_POST['single_fee_amount'])){
        $monthly_fee_amount = (float) $_POST['single_fee_amount'];
    }
    
    $transaction_description = isset($_POST['single_fee_description']) ? 
        strip_tags(mysqli_real_escape_string($GLOBALS['DB'], $_POST['single_fee_description'])) : 
        "Taxa mensal do motorista";
    
    $query = sprintf('SELECT %1$stbl_drivers.driver_id, %1$stbl_drivers.firstname, %1$stbl_drivers.lastname, 
    %1$stbl_drivers.wallet_amount, %1$stbl_currencies.symbol, %1$stbl_currencies.iso_code, %1$stbl_currencies.exchng_rate 
    FROM %1$stbl_drivers 
    LEFT JOIN %1$stbl_routes ON %1$stbl_routes.id = %1$stbl_drivers.route_id
    LEFT JOIN %1$stbl_currencies ON %1$stbl_currencies.id = %1$stbl_routes.city_currency_id
    WHERE %1$stbl_drivers.driver_id = %2$d', 
    DB_TBL_PREFIX, $driver_id);
    
    if($result = mysqli_query($GLOBALS['DB'], $query)){
        if(mysqli_num_rows($result)){
            $driver = mysqli_fetch_assoc($result);
            
            $driver_name = $driver['firstname'] . ' ' . $driver['lastname'];
            $wallet_amount = (float) $driver['wallet_amount'];
            $currency_symbol = $driver['symbol'];
            $currency_code = $driver['iso_code'];
            $exchange_rate = $driver['exchng_rate'];
            
            $fee_amount_converted = $monthly_fee_amount / $exchange_rate;
            
            $update_query = sprintf('UPDATE %stbl_drivers SET wallet_amount = wallet_amount - %f WHERE driver_id = %d', 
                DB_TBL_PREFIX, $fee_amount_converted, $driver_id);
            
            if(mysqli_query($GLOBALS['DB'], $update_query)){
                $new_balance = $wallet_amount - $fee_amount_converted;
                
                $transaction_id = crypto_string();
                
                $transaction_query = sprintf('INSERT INTO %stbl_wallet_transactions 
                (transaction_id, amount, cur_symbol, cur_exchng_rate, cur_code, wallet_balance, user_id, user_type, `desc`, `type`, transaction_date) 
                VALUES ("%s", "%f", "%s", "%s", "%s", "%f", "%d", "%d", "%s", "%d", "%s")',
                DB_TBL_PREFIX,
                $transaction_id,
                $monthly_fee_amount,
                $currency_symbol,
                $exchange_rate,
                $currency_code,
                $new_balance,
                $driver_id,
                1, 
                $transaction_description,
                3, 
                gmdate('Y-m-d H:i:s', time())
                );
                
                if(mysqli_query($GLOBALS['DB'], $transaction_query)){
                  
                    $update_last_fee_date = sprintf('UPDATE %stbl_drivers SET last_monthly_fee_date = "%s" WHERE driver_id = %d', 
                        DB_TBL_PREFIX, 
                        gmdate('Y-m-d H:i:s', time()),
                        $driver_id);
                    mysqli_query($GLOBALS['DB'], $update_last_fee_date);
                    
           
                    if($force_charge) {
                        $_SESSION['action_success'][] = "Taxa mensal aplicada com sucesso para o motorista {$driver_name} (cobrança forçada).";
                    } else {
                        $_SESSION['action_success'][] = "Taxa mensal aplicada com sucesso para o motorista {$driver_name}.";
                    }
                    
                    if($wallet_amount < $fee_amount_converted) {
                        $_SESSION['action_info'][] = "A carteira do motorista ficou com saldo negativo e será compensada em ganhos futuros.";
                    }
                } else {
                    $_SESSION['action_error'][] = "Erro ao registrar a transação para o motorista {$driver_name}.";
                }
            } else {
                $_SESSION['action_error'][] = "Erro ao atualizar a carteira do motorista {$driver_name}.";
            }
        } else {
            $_SESSION['action_error'][] = "Motorista não encontrado.";
        }
        mysqli_free_result($result);
    } else {
        $_SESSION['action_error'][] = "Erro ao consultar o banco de dados.";
    }
    
    header("Location: " . htmlspecialchars($_SERVER['SCRIPT_NAME']));
    exit;
}
if(!empty($_POST) && isset($_POST['run_monthly_fee'])){
    
    if(isset($_POST['quick_fee_amount']) && is_numeric($_POST['quick_fee_amount'])){
        $monthly_fee_amount = (float) $_POST['quick_fee_amount'];
    }
    
    $transaction_description = isset($_POST['quick_fee_description']) ? 
        strip_tags(mysqli_real_escape_string($GLOBALS['DB'], $_POST['quick_fee_description'])) : 
        "Taxa mensal do motorista";
    
    $current_month = date('Y-m-01'); 
    $next_month = date('Y-m-01', strtotime('+1 month')); 
    
    $query = sprintf('SELECT %1$stbl_drivers.driver_id, %1$stbl_drivers.firstname, %1$stbl_drivers.lastname, 
    %1$stbl_drivers.wallet_amount, %1$stbl_drivers.last_monthly_fee_date, %1$stbl_currencies.symbol, 
    %1$stbl_currencies.iso_code, %1$stbl_currencies.exchng_rate 
    FROM %1$stbl_drivers 
    LEFT JOIN %1$stbl_routes ON %1$stbl_routes.id = %1$stbl_drivers.route_id
    LEFT JOIN %1$stbl_currencies ON %1$stbl_currencies.id = %1$stbl_routes.city_currency_id
    WHERE %1$stbl_drivers.driver_status = 1 
    AND (%1$stbl_drivers.last_monthly_fee_date IS NULL OR %1$stbl_drivers.last_monthly_fee_date < "%2$s")', 
    DB_TBL_PREFIX, $current_month);
    
    if($result = mysqli_query($GLOBALS['DB'], $query)){
        $batch_success_count = 0;
        $batch_error_count = 0;
        
        while($driver = mysqli_fetch_assoc($result)){
            $driver_id = $driver['driver_id'];
            $driver_name = $driver['firstname'] . ' ' . $driver['lastname'];
            $wallet_amount = (float) $driver['wallet_amount'];
            $currency_symbol = $driver['symbol'];
            $currency_code = $driver['iso_code'];
            $exchange_rate = $driver['exchng_rate'];
            
            $fee_amount_converted = $monthly_fee_amount / $exchange_rate;
            
            $update_query = sprintf('UPDATE %stbl_drivers SET wallet_amount = wallet_amount - %f WHERE driver_id = %d', 
                DB_TBL_PREFIX, $fee_amount_converted, $driver_id);
            
            if(mysqli_query($GLOBALS['DB'], $update_query)){
                $new_balance = $wallet_amount - $fee_amount_converted;
                
                $transaction_id = crypto_string();
                
                $transaction_query = sprintf('INSERT INTO %stbl_wallet_transactions 
                (transaction_id, amount, cur_symbol, cur_exchng_rate, cur_code, wallet_balance, user_id, user_type, `desc`, `type`, transaction_date) 
                VALUES ("%s", "%f", "%s", "%s", "%s", "%f", "%d", "%d", "%s", "%d", "%s")',
                DB_TBL_PREFIX,
                $transaction_id,
                $monthly_fee_amount,
                $currency_symbol,
                $exchange_rate,
                $currency_code,
                $new_balance,
                $driver_id,
                1, 
                $transaction_description,
                3, 
                gmdate('Y-m-d H:i:s', time())
                );
                
                if(mysqli_query($GLOBALS['DB'], $transaction_query)){
                    $update_last_fee_date = sprintf('UPDATE %stbl_drivers SET last_monthly_fee_date = "%s" WHERE driver_id = %d', 
                        DB_TBL_PREFIX, 
                        gmdate('Y-m-d H:i:s', time()),
                        $driver_id);
                    mysqli_query($GLOBALS['DB'], $update_last_fee_date);
                    
                    $batch_success_count++;
                } else {
                    $batch_error_count++;
                }
            } else {
                $batch_error_count++;
            }
        }
        
        if($batch_success_count > 0){
            $_SESSION['action_success'][] = "Taxa mensal aplicada com sucesso para {$batch_success_count} motorista(s).";
        }
        
        if($batch_error_count > 0){
            $_SESSION['action_error'][] = "Não foi possível aplicar a taxa para {$batch_error_count} motorista(s).";
        }
        
        if($batch_success_count == 0 && $batch_error_count == 0){
            $_SESSION['action_info'][] = "Não há motoristas pendentes para cobrança neste mês.";
        }
    }
    
    header("Location: " . htmlspecialchars($_SERVER['SCRIPT_NAME']));
    exit;
}
if(!empty($_POST) && isset($_POST['apply_fee'])){
    
    $execute_mode = isset($_POST['execute_mode']) ? $_POST['execute_mode'] : 'manual';
    
    $selected_drivers = [];
    if($execute_mode == 'manual' && isset($_POST['selected_drivers'])){
        $selected_drivers = $_POST['selected_drivers'];
    }
    
    if(isset($_POST['fee_amount']) && is_numeric($_POST['fee_amount'])){
        $monthly_fee_amount = (float) $_POST['fee_amount'];
    }
    
    $transaction_description = isset($_POST['fee_description']) ? 
        strip_tags(mysqli_real_escape_string($GLOBALS['DB'], $_POST['fee_description'])) : 
        "Taxa mensal do motorista";
    
    $query_modifier = "driver_status = 1";
    
    if($execute_mode == 'manual' && !empty($selected_drivers)){
        $driver_ids = implode(',', array_map('intval', $selected_drivers));
        $query_modifier .= " AND driver_id IN ($driver_ids)";
    }
    
    $query = sprintf('SELECT %1$stbl_drivers.driver_id, %1$stbl_drivers.firstname, %1$stbl_drivers.lastname, 
    %1$stbl_drivers.wallet_amount, %1$stbl_drivers.last_monthly_fee_date, %1$stbl_currencies.symbol, %1$stbl_currencies.iso_code, %1$stbl_currencies.exchng_rate 
    FROM %1$stbl_drivers 
    LEFT JOIN %1$stbl_routes ON %1$stbl_routes.id = %1$stbl_drivers.route_id
    LEFT JOIN %1$stbl_currencies ON %1$stbl_currencies.id = %1$stbl_routes.city_currency_id
    WHERE %2$s', DB_TBL_PREFIX, $query_modifier);
    
    if($result = mysqli_query($GLOBALS['DB'], $query)){
        while($driver = mysqli_fetch_assoc($result)){
            $driver_id = $driver['driver_id'];
            $driver_name = $driver['firstname'] . ' ' . $driver['lastname'];
            $wallet_amount = (float) $driver['wallet_amount'];
            $currency_symbol = $driver['symbol'];
            $currency_code = $driver['iso_code'];
            $exchange_rate = $driver['exchng_rate'];
            $last_fee_date = isset($driver['last_monthly_fee_date']) ? $driver['last_monthly_fee_date'] : null;
            
            if(isset($already_charged[$driver_id])){
                $processed_drivers[] = [
                    'driver_id' => $driver_id,
                    'name' => $driver_name,
                    'amount' => $monthly_fee_amount,
                    'currency' => $currency_symbol,
                    'status' => 'skipped',
                    'message' => 'Já cobrado este mês em ' . date('d/m/Y H:i', strtotime($already_charged[$driver_id]['date']))
                ];
                continue;
            }
            
            $fee_amount_converted = $monthly_fee_amount / $exchange_rate;
            
            $update_query = sprintf('UPDATE %stbl_drivers SET wallet_amount = wallet_amount - %f WHERE driver_id = %d', 
                DB_TBL_PREFIX, $fee_amount_converted, $driver_id);
            
            if(mysqli_query($GLOBALS['DB'], $update_query)){
                $new_balance = $wallet_amount - $fee_amount_converted;
                
                $transaction_id = crypto_string();
                
                $transaction_query = sprintf('INSERT INTO %stbl_wallet_transactions 
                (transaction_id, amount, cur_symbol, cur_exchng_rate, cur_code, wallet_balance, user_id, user_type, `desc`, `type`, transaction_date) 
                VALUES ("%s", "%f", "%s", "%s", "%s", "%f", "%d", "%d", "%s", "%d", "%s")',
                DB_TBL_PREFIX,
                $transaction_id,
                $monthly_fee_amount,
                $currency_symbol,
                $exchange_rate,
                $currency_code,
                $new_balance,
                $driver_id,
                1, 
                $transaction_description,
                3, 
                gmdate('Y-m-d H:i:s', time())
                );
                
                if(mysqli_query($GLOBALS['DB'], $transaction_query)){
                    $update_last_fee_date = sprintf('UPDATE %stbl_drivers SET last_monthly_fee_date = "%s" WHERE driver_id = %d', 
                        DB_TBL_PREFIX, 
                        gmdate('Y-m-d H:i:s', time()),
                        $driver_id);
                    mysqli_query($GLOBALS['DB'], $update_last_fee_date);
                    
                    $status_message = $wallet_amount < $fee_amount_converted ? 
                        'Cobrado com sucesso (saldo negativo)' : 'Cobrado com sucesso';
                    $processed_drivers[] = [
                        'driver_id' => $driver_id,
                        'name' => $driver_name,
                        'amount' => $monthly_fee_amount,
                        'currency' => $currency_symbol,
                        'status' => 'success',
                        'message' => $status_message
                    ];
                    $success_count++;
                } else {
                    $processed_drivers[] = [
                        'driver_id' => $driver_id,
                        'name' => $driver_name,
                        'amount' => $monthly_fee_amount,
                        'currency' => $currency_symbol,
                        'status' => 'error',
                        'message' => 'Erro ao registrar transação'
                    ];
                    $error_count++;
                }
            } else {
                $processed_drivers[] = [
                    'driver_id' => $driver_id,
                    'name' => $driver_name,
                    'amount' => $monthly_fee_amount,
                    'currency' => $currency_symbol,
                    'status' => 'error',
                    'message' => 'Erro ao atualizar carteira'
                ];
                $error_count++;
            }
        }
    }
    
    if($success_count > 0){
        $_SESSION['action_success'][] = "Taxa mensal aplicada com sucesso para {$success_count} motorista(s).";
    }
    
    if($error_count > 0){
        $_SESSION['action_error'][] = "Não foi possível aplicar a taxa para {$error_count} motorista(s). Verifique o relatório para mais detalhes.";
    }
}

$drivers_list = [];
$query = sprintf('SELECT driver_id, firstname, lastname, wallet_amount, last_monthly_fee_date FROM %stbl_drivers WHERE driver_status = 1 ORDER BY firstname', DB_TBL_PREFIX);
if($result = mysqli_query($GLOBALS['DB'], $query)){
    while($row = mysqli_fetch_assoc($result)){
        $drivers_list[] = $row;
    }
}
ob_start();
?>

<div class="row">
    <div class="col-sm-12">
        <div class="alert alert-info alert-dismissible">
            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
            <h4><i class="icon fa fa-info"></i> Informação</h4>
            Aplicar a taxa mensal dos motoristas. A taxa será debitada da carteira de cada motorista ativo. Se o saldo do motorista for insuficiente, a carteira ficará com saldo negativo e o valor será descontado dos próximos ganhos.
        </div>
    </div>
</div>

<div class="row">
    <div class="col-sm-12">
        <div class="box box-warning">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-bolt"></i> Cobrança Rápida Mensal</h3>
            </div>
            <div class="box-body">
                <p>Use esta opção para cobrar a taxa mensal de todos os motoristas ativos que ainda não foram cobrados no mês atual.</p>
                
                <div class="row">
                    <div class="col-sm-6">
                        <form action="<?php echo htmlspecialchars($_SERVER['SCRIPT_NAME']); ?>" method="post">
                            <div class="form-group">
                                <label for="quick-fee-amount">Valor da Taxa (R$)</label>
                                <input type="number" step="0.01" class="form-control" id="quick-fee-amount" name="quick_fee_amount" value="<?php echo $monthly_fee_amount; ?>" style="max-width: 200px;">
                            </div>
                            
                            <div class="form-group">
                                <label for="quick-fee-description">Descrição da Taxa</label>
                                <input type="text" class="form-control" id="quick-fee-description" name="quick_fee_description" value="Taxa mensal do motorista" style="max-width: 400px;">
                            </div>
                            
                            <button type="submit" class="btn btn-warning btn-lg" name="run_monthly_fee" value="1">
                                <i class="fa fa-money"></i> Executar Cobrança Mensal
                            </button>
                        </form>
                    </div>
                    
                    <div class="col-sm-6">
                        <div class="info-box">
                            <span class="info-box-icon bg-aqua"><i class="fa fa-calendar-check-o"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Status do Mês Atual</span>
                                <span class="info-box-number">
                                    <?php 
                                    $total_count = isset($drivers_list) ? count($drivers_list) : 0;
                                    $charged_count = isset($already_charged) ? count($already_charged) : 0;
                                    $pending_count = $total_count - $charged_count;
                                    
                                    echo "Total: {$total_count} motoristas | ";
                                    echo "Cobrados: {$charged_count} | ";
                                    echo "Pendentes: {$pending_count}";
                                    ?>
                                </span>
                                <div class="progress">
                                    <div class="progress-bar" style="width: <?php echo $total_count > 0 ? ($charged_count / $total_count * 100) : 0; ?>%"></div>
                                </div>
                                <span class="progress-description">
                                    <?php echo $total_count > 0 ? round($charged_count / $total_count * 100) : 0; ?>% Concluído
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-sm-12">
        <div class="box box-info">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-user"></i> Cobrança de Motorista Específico</h3>
            </div>
            <div class="box-body">
                <p>Use esta opção para cobrar a taxa mensal de um motorista específico, útil para testes ou processamento individual.</p>
                
                <div class="row">
                    <div class="col-sm-12">
                        <form action="<?php echo htmlspecialchars($_SERVER['SCRIPT_NAME']); ?>" method="post" class="form-horizontal">
                            <div class="form-group">
                                <label for="single-driver" class="col-sm-3 control-label">Selecionar Motorista</label>
                                <div class="col-sm-6">
                                    <select class="form-control" id="single-driver" name="single_driver" required>
                                        <option value="">-- Selecione um motorista --</option>
                                        <?php foreach($drivers_list as $driver): 
                                            $already_charged_this_month = isset($already_charged[$driver['driver_id']]);
                                            $disabled = $already_charged_this_month ? 'disabled' : '';
                                            $style = $already_charged_this_month ? 'style="color:#999;"' : '';
                                        ?>
                                        <option value="<?php echo $driver['driver_id']; ?>" <?php echo $disabled; ?> <?php echo $style; ?>>
                                            <?php echo $driver['firstname'] . ' ' . $driver['lastname'] . ' (Saldo: R$ ' . number_format($driver['wallet_amount'], 2, ',', '.') . ')'; ?>
                                            <?php if($already_charged_this_month): ?> [Já cobrado este mês]<?php endif; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="single-fee-amount" class="col-sm-3 control-label">Valor da Taxa (R$)</label>
                                <div class="col-sm-6">
                                    <input type="number" step="0.01" class="form-control" id="single-fee-amount" name="single_fee_amount" value="<?php echo $monthly_fee_amount; ?>" required style="max-width: 200px;">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="single-fee-description" class="col-sm-3 control-label">Descrição da Taxa</label>
                                <div class="col-sm-6">
                                    <input type="text" class="form-control" id="single-fee-description" name="single_fee_description" value="Taxa mensal do motorista" required style="max-width: 400px;">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <div class="col-sm-offset-3 col-sm-6">
                                    <div class="checkbox">
                                        <label>
                                            <input type="checkbox" name="force_charge" value="1"> Forçar cobrança (mesmo se já cobrado este mês)
                                        </label>
                                    </div>
                                    <p class="help-block">Use esta opção com cuidado, apenas para testes ou correções.</p>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <div class="col-sm-offset-3 col-sm-6">
                                    <button type="submit" class="btn btn-info" name="charge_single_driver" value="1">
                                        <i class="fa fa-user-plus"></i> Cobrar Motorista Selecionado
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-sm-12">
        <div class="box box-success">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-cog"></i> Configuração Avançada</h3>
            </div>
            <div class="box-body">
                <form enctype="multipart/form-data" class="form-horizontal" action="<?php echo htmlspecialchars($_SERVER['SCRIPT_NAME']); ?>" method="post">
                    <div class="form-group">
                        <label for="execute_mode" class="col-sm-3 control-label">Modo de Execução</label>
                        <div class="col-sm-6">
                            <select class="form-control" id="execute_mode" name="execute_mode">
                                <option value="manual">Manual (selecionar motoristas)</option>
                                <option value="automatic">Automático (todos os motoristas ativos)</option>
                            </select>
                        </div>
                    </div>
                    
                    <div id="drivers_selection" class="form-group">
                        <label for="selected_drivers" class="col-sm-3 control-label">Selecionar Motoristas</label>
                        <div class="col-sm-6">
                            <select multiple class="form-control" id="selected_drivers" name="selected_drivers[]" style="height: 200px;">
                                <?php foreach($drivers_list as $driver): 
                                    $already_charged_this_month = isset($already_charged[$driver['driver_id']]);
                                    $disabled = $already_charged_this_month ? 'disabled' : '';
                                    $style = $already_charged_this_month ? 'style="color:#999;"' : '';
                                ?>
                                <option value="<?php echo $driver['driver_id']; ?>" <?php echo $disabled; ?> <?php echo $style; ?>>
                                    <?php echo $driver['firstname'] . ' ' . $driver['lastname'] . ' (Saldo: R$ ' . number_format($driver['wallet_amount'], 2, ',', '.') . ')'; ?>
                                    <?php if($already_charged_this_month): ?> [Já cobrado este mês]<?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="help-block">Ctrl+clique para selecionar múltiplos motoristas</p>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="fee_amount" class="col-sm-3 control-label">Valor da Taxa (R$)</label>
                        <div class="col-sm-6">
                            <input type="number" step="0.01" class="form-control" id="fee_amount" name="fee_amount" value="<?php echo $monthly_fee_amount; ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="fee_description" class="col-sm-3 control-label">Descrição da Taxa</label>
                        <div class="col-sm-6">
                            <input type="text" class="form-control" id="fee_description" name="fee_description" value="Taxa mensal do motorista" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="col-sm-offset-3 col-sm-6">
                            <button type="submit" class="btn btn-primary" name="apply_fee" value="1">Aplicar Taxa</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php if(!empty($processed_drivers)): ?>
<div class="row">
    <div class="col-sm-12">
        <div class="box box-success">
            <div class="box-header with-border">
                <h3 class="box-title">Resultado da Operação</h3>
            </div>
            <div class="box-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>ID</th>
                                <th>Nome</th>
                                <th>Valor</th>
                                <th>Status</th>
                                <th>Mensagem</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $count = 1; foreach($processed_drivers as $driver): 
                                $row_class = '';
                                if($driver['status'] == 'success') {
                                    $row_class = 'success';
                                } elseif($driver['status'] == 'error') {
                                    $row_class = 'danger';
                                } elseif($driver['status'] == 'skipped') {
                                    $row_class = 'warning';
                                }
                            ?>
                            <tr class="<?php echo $row_class; ?>">
                                <td><?php echo $count++; ?></td>
                                <td><?php echo $driver['driver_id']; ?></td>
                                <td><?php echo $driver['name']; ?></td>
                                <td><?php echo $driver['currency'] . $driver['amount']; ?></td>
                                <td><?php 
                                    if($driver['status'] == 'success') {
                                        echo 'Sucesso';
                                    } elseif($driver['status'] == 'error') {
                                        echo 'Erro';
                                    } elseif($driver['status'] == 'skipped') {
                                        echo 'Pulado';
                                    }
                                ?></td>
                                <td><?php echo isset($driver['message']) ? $driver['message'] : ''; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    jQuery(document).ready(function() {
        jQuery('#execute_mode').change(function() {
            if(jQuery(this).val() == 'manual') {
                jQuery('#drivers_selection').show();
            } else {
                jQuery('#drivers_selection').hide();
            }
        });
    });
</script>

<?php
if(!empty($_SESSION['action_success'])){
    $msgs = '';
    foreach($_SESSION['action_success'] as $action_success){
        $msgs .= "<p style='text-align:left;'><i style='color:green;' class='fa fa-circle-o'></i> ".$action_success . "</p>";
    }

    $cache_prevent = RAND();
    echo '<script>
setTimeout(function(){ 
    jQuery(function(){
        swal({
            title: "<h1>Sucesso</h1>",
            text: "'.$msgs.'",
            imageUrl: "../img/success_.gif?a='.$cache_prevent.'",
            html: true
        });
    });
}, 500);
</script>';
   unset($_SESSION['action_success']);
}

if(!empty($_SESSION['action_error'])){
    $msgs = '';
    foreach($_SESSION['action_error'] as $action_error){
        $msgs .= "<p style='text-align:left;'><i style='color:red;' class='fa fa-circle-o'></i> ".$action_error . "</p>";
    }

    $cache_prevent = RAND();
    echo '<script>
    setTimeout(function(){ 
        jQuery(function(){
            swal({
                title: "<h1>Erro</h1>",
                text: "'.$msgs.'",
                imageUrl: "../img/success_.gif?a='.$cache_prevent.'",
                html: true
            });
        });
    }, 500);
    </script>';
    unset($_SESSION['action_error']);
}

if(!empty($_SESSION['action_info'])){
    $msgs = '';
    foreach($_SESSION['action_info'] as $action_info){
        $msgs .= "<p style='text-align:left;'><i style='color:blue;' class='fa fa-circle-o'></i> ".$action_info . "</p>";
    }

    $cache_prevent = RAND();
    echo '<script>
    setTimeout(function(){ 
        jQuery(function(){
            swal({
                title: "<h1>Informação</h1>",
                text: "'.$msgs.'",
                imageUrl: "../img/success_.gif?a='.$cache_prevent.'",
                html: true
            });
        });
    }, 500);
    </script>';
    unset($_SESSION['action_info']);
}

$pageContent = ob_get_clean();
$GLOBALS['admin_template']['page_content'] = $pageContent;
include "../../drop-files/templates/admin/admin-interface.php";
?>
