<?php
$user=JFactory::getUser();
if(isset($_POST['token_id'])){
        require(dirname(dirname(__FILE__)) . '/Openpay/Openpay.php');
        $openpay = Openpay::getInstance($account, $privatekey);
        $customer = array(
             'name' => $user->name,
             'last_name' => $_POST["last_name"],
             'phone_number' => $_POST["phone_number"],
             'email' => $user->email);
        if(isset($_POST['holder_name'])) {
			$customer['name'] = explode(' ', $_POST['holder_name'])[0];
			$customer['last_name'] = explode(' ', $_POST['holder_name'], 2)[1];
		}
        $chargeData = array(
            'method' => 'card',
            'source_id' => $_POST["token_id"],
			'use_3d_secure' => true,
            'amount' => (float)$_POST["amount"],
            'currency' => $_POST['currency'],
            'description' => str_replace('{id}', $oid, $_POST["description"]),
            'use_card_points' => $_POST["use_card_points"], // Opcional, si estamos usando puntos
            'device_session_id' => $_POST["deviceIdHiddenFieldName"],
            'customer' => $customer
            );
        $db=JFactory::getDBO();
        $query='ALTER TABLE `#__users` ADD `card` MEDIUMTEXT NULL AFTER `requireReset`';
        $db->setQuery($query);
        try{
                //crear en tabla usuarios un campo para json de tarjeta y usarlo en nuevas ordenes
                $db->query();
        }catch(Exception $e){}
        $query='ALTER TABLE `#__zoo_zl_zoocart_orders` ADD `openpay` MEDIUMTEXT NULL AFTER `hash`;';
        $db->setQuery($query);
        try{
                $db->query();
        }catch(Exception $e){}
        try{//print_r($chargeData);echo $privatekey;exit;
                $card=array(
                        'holder_name'=>$_POST['holder_name'],
                        'card_number'=>$_POST['card_number'],
                        'expiration_month'=>$_POST['expiration_month'],
                        'expiration_year'=>$_POST['expiration_year']
                            );
                $query='UPDATE #__users SET card="'.$db->quote(json_encode($card)).'" WHERE id='.(int)$user->id;
                $db->setQuery($query);
                try{
                        $db->query();
                }catch(Exception $e){}
                $charge = $openpay->charges->create($chargeData);
                //pago correcto, aplica el status y datos de tarjeta para el pago
                //obtener id de orden satisfactoria
                $query='SELECT id FROM #__zoo_zl_zoocart_orderstates WHERE name="PLG_ZOOCART_ORDER_STATE_COMPLETED"';
                $db->setQuery( $query );
                $tid=(int)$db->loadResult();
                //con el id, actualizar la orden a pagada
                $query='UPDATE #__zoo_zl_zoocart_orders SET state='.$tid.', openpay="'.$db->quote(json_encode($card)).'" WHERE id='.(int)$oid;
                $db->setQuery( $query );
                try{//se omite el paso de actualizacion de orden para fines de prueba de openpay
                        //$db->query();
                }catch(Exception $e){}
                //print_r($_POST);
        }catch(Exception $e){
			echo '<div class="uk-alert uk-alert-danger">';
			echo '<b>Error ('.$e->getCode().'):</b> ';
			if($e->getMessage()=='merchant not allowed to process points')
					echo 'El pago con puntos no es permitido para esta tarjeta.';
			elseif($e->getMessage()=='This source_id was already used')
					echo 'Ya has hecho pago con este ID, no se pudo procesar el pago.';
			elseif($e->getCode()==1003 && $test==1)
					echo $e->getMessage();
			else
					echo parseErrorOpenPay::code($e->getCode());
			echo '</div>';
			return;
        }
        ?>
<div class="uk-alert uk-alert-success uk-text-center uk-text-contrast">
	<?php echo JText::_('PLG_ZLFRAMEWORK_PAY');//$this->message; ?>
</div>
<style type="text/css">
#zx-zoocart-order:not(.zx), dl.uk-description-list{
        display: none !important;
}
</style>
        <?php
        return;
}
?>
<script type="text/javascript" 
    src="https://openpay.s3.amazonaws.com/openpay.v1.min.js"></script>
<script type='text/javascript' 
    src="https://openpay.s3.amazonaws.com/openpay-data.v1.min.js"></script>
<!--https://www.openpay.mx/docs/card-charge.html-->

<script type="text/javascript">
        jQuery(document).ready(function($) {

            OpenPay.setId('<?=$account?>');
            OpenPay.setApiKey('<?=$publickey?>');
            <?php if($test==1):?>
            OpenPay.setSandboxMode(true);
            console.warn('OpenPay Sandbox Mode');
            
            $('[data-openpay-card="holder_name"]').val('Debug Holder');
            $('[data-openpay-card="card_number"').val('4242424242424242');
            $('[data-openpay-card="expiration_month"]').val('12');
            $('[data-openpay-card="expiration_year"]').val('20');
            $('[data-openpay-card="cvv2"]').val('123');
            <?php endif; ?>
            //Se genera el id de dispositivo
            var deviceSessionId = OpenPay.deviceData.setup("payment-form", "deviceIdHiddenFieldName");
            
            $('#pay-button').on('click', function(event) {
                event.preventDefault();
                //validaciones
                var errors='<b>Verifica los siguientes datos:</b>';
                var hasErrors=false;
                if($('[data-openpay-card="holder_name"]').val()===''){
                        errors+='<div>Ingresa el nombre del titular de la tarjeta.</div>';
                        hasErrors=true;
                }
                if(!OpenPay.card.validateCardNumber($('[data-openpay-card="card_number"]').val())){
                        errors+='<div>El número de tarjeta es inválido.</div>';
                        hasErrors=true;
                }
                <?php if($test==0):?>
                if(!OpenPay.card.validateExpiry($('[data-openpay-card="expiration_month"]').val(), $('[data-openpay-card="expiration_year"]').val())){
                        //errors+='<div>La fecha de expiración es inválido.</div>';
                        //hasErrors=true;
                }
                <?php endif; ?>
                if(!OpenPay.card.validateCVC($('[data-openpay-card="cvv2"]').val(), $('[data-openpay-card="card_number"]').val())){
                        errors+='<div>El código de seguridad es inválido.</div>';
                        hasErrors=true;
                }
                if(hasErrors){
                        UIkit.modal.alert(errors);
                        return;
                }
                $("#pay-button").prop( "disabled", true);
                OpenPay.token.extractFormAndCreate('payment-form', sucess_callbak, error_callbak);                
            });

            var sucess_callbak = function(response) {
              var token_id = response.data.id;
              $('#token_id').val(token_id);
              if (response.data.card.points_card) {
                  // Si la tarjeta permite usar puntos, mostrar el cuadro de diálogo
                  $("#card-points-dialog").modal("show");
              } else {
                  // De otra forma, realizar el pago inmediatamente
                  $('#payment-form').submit();
              }
            };

            var error_callbak = function(response) {
                var desc = response.data.description != undefined ? response.data.description : response.message;
                UIkit.modal.alert("ERROR [" + response.status + "] " + desc);
                $("#pay-button").prop("disabled", false);
            };
            $("#points-yes-button").on('click', function(){
                $('#use_card_points').val('true');
                $('#payment-form').submit();
            });
                      
            $("#points-no-button").on('click', function(){
                $('#use_card_points').val('false');
                $('#payment-form').submit();
            });
            
            $('[data-openpay-card="expiration_month"]').mask('99');
            $('[data-openpay-card="expiration_year"]').mask('99');
            $('[data-openpay-card="card_number"').mask('999999999999999?9');
            $('[data-openpay-card="cvv2"]').mask('999?9');
        
        });
    </script>

<style>
.pymnt-cntnt .uk-grid-collapse > .uk-grid{
    padding-left: 0px !important;
    margin-left: -25px !important;
}
</style>



<?php
$path=str_replace(JPATH_ROOT, '', dirname(dirname(__FILE__)));
$user->card=json_decode($user->card!=''?str_replace(array("'{", "}'"), array('{', '}'), $user->card):'{}');
?>

<div class="bkng-tb-cntnt">
    <div class="pymnts">
        <form action="<?=$return_url?>" method="POST" id="payment-form" class="uk-form">
            <input type="hidden" name="token_id" id="token_id">
            <input type="hidden" name="description" id="description" value="<?=$description?>">
            <input type="hidden" name="use_card_points" id="use_card_points" value="false">
            <div class="pymnt-itm card active">
                <h2 class="uk-panel uk-panel-box uk-panel-box-primary uk-margin-remove uk-text-contrast">Tarjeta de crédito o débito</h2>
                <div class="pymnt-cntnt uk-panel uk-panel-box"><div class="uk-grid uk-grid-collapse">
                    <div class="uk-width-1-1 card-expl"><div class="uk-grid uk-grid-collapse">
                        <div class="uk-width-medium-1-3 credit"><h4 class="uk-margin-remove">Tarjetas de crédito</h4>
                            <img src="<?=$path?>/Openpay/images/cards1.png" class="" />
                        </div>
                        <div class="uk-width-medium-2-3 debit"><h4 class="uk-margin-remove">Tarjetas de débito</h4>
                            <img src="<?=$path?>/Openpay/images/cards2.png" class="" />
                        </div>
                    </div></div>
                    <?php if(isset($user->card->card_number)): ?>
                    <div class="uk-width-1-1">
                        <div class="uk-badge uk-badge-warning uk-width-1-1 uk-margin-top uk-text-large">Se ha cargado el último método de pago utilizado, puedes cambiarlo si así lo requieres.</div>
                    </div>
                    <?php endif; ?>
                    <div class="uk-width-1-1 sctn-row uk-margin-top"><div class="uk-grid uk-grid-collapse">
                        <div class="uk-width-medium-1-2 sctn-col l">
                            <label>Nombre del titular</label>
                            <input type="text" value="<?=$user->card->holder_name?>" placeholder="Como aparece en la tarjeta" autocomplete="off" data-openpay-card="holder_name" name="holder_name" class="uk-form-large uk-width-1-1">
                        </div>
                        <div class="uk-width-medium-1-2 sctn-col">
                            <label>Número de tarjeta</label>
                            <input type="text" value="<?=$user->card->card_number?>" autocomplete="off" data-openpay-card="card_number" name="card_number" class="uk-form-large uk-width-1-1">
                        </div>
                        <div class="uk-width-1-1 sctn-row uk-margin-top uk-margin-bottom"><div class="uk-grid uk-grid -collapse">
                            <div class="uk-width-medium-1-2 sctn-col l"><div class="uk-grid">
                                <label class="uk-width-1-1">Fecha de expiración</label>
                                <div class="uk-width-medium-1-2 sctn-col half l">
                                        <select placeholder="Mes" data-openpay-card="expiration_month" name="expiration_month" class="uk-form-large uk-width-1-1">
                                                <?php for($m=1; $m<=12; $m++): ?>
                                                <option value="<?=($m<10?'0':'').$m?>"<?=(int)date('m')==(int)$m?' selected':''?>><?=($m<10?'0':'').$m?></option>
                                                <?php endfor; ?>
                                        </select>
                                </div>
                                <div class="uk-width-medium-1-2 sctn-col half l">
                                        <select placeholder="Año" data-openpay-card="expiration_year" name="expiration_year" class="uk-form-large uk-width-1-1">
                                                <?php for($m=(int)date('y'); $m<=(int)date('y')+10; $m++): ?>
                                                <option value="<?=($m<10?'0':'').$m?>"<?=(int)date('y')==(int)$m?' selected':''?>>20<?=($m<10?'0':'').$m?></option>
                                                <?php endfor; ?>
                                        </select>
                                </div>
                            </div></div>
                            <div class="uk-width-medium-1-2 sctn-col cvv">
                                <div class="sctn-col half l"><div class="uk-grid">
                                    <label class="uk-width-1-1">Código de seguridad</label>
                                    <div class="uk-width-medium-1-2">
                                        <input type="text" placeholder="3 o 4 dígitos" autocomplete="off" data-openpay-card="cvv2" name="cvv2" class="uk-form-large uk-width-1-1">
                                    </div>
                                    <img src="<?=$path?>/Openpay/images/cvv.png" class="uk-width-medium-1-2" />
                                </div></div>
                            </div>
                        </div></div>
                        <div class="uk-width-medium-3-6 uk-text-center uk-text-bold uk-text-large">
                                <div class="uk-panel uk-panel-box">
                                      $<?=$amount?> <?=$currency?>
                                      <input type="hidden" name="amount" value="<?=$amount?>" />
                                      <input type="hidden" name="currency" value="<?=$currency?>" />
                                </div>
                        </div>
                        <div class="uk-width-medium-1-6 uk-text-right uk-text-small openpay">
                            Transacciones realizadas vía:
                            <div class="logo"><img src="<?=$path?>/Openpay/images/openpay.png" /></div>
                        </div>
                        <div class="uk-width-medium-1-3 uk-text-small shield">
                            <img src="<?=$path?>/Openpay/images/security.png" class="uk-float-left uk-margin-small-right" />
                            Tus pagos se realizan de forma segura con encriptación de 256 bits
                        </div>
                    </div>
                    <div class="sctn-row uk-margin-top uk-text-center">
                            <a class="uk-width-medium-1-3 uk-button uk-button-primary uk-button-large rght" id="pay-button">Pagar</a>
                    </div>
                </div>
            </div>
<div class="modal fade" role="dialog" id="card-points-dialog">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h4 class="modal-title uk-margin-remove">Pagar con Puntos</h4>
      </div>
      <div class="modal-body">
        <p><i class="uk-icon-check-square-o uk-icon-medium uk-float-left uk-margin-small-right green"></i> Tu tarjeta acepta hacer pago con puntos, <br>¿Deseas usar los puntos de tu tarjeta para realizar este pago?</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal" id="points-no-button">No</button>
        <button type="button" class="btn btn-primary" data-dismiss="modal" id="points-yes-button">Si</button>
      </div>
    </div>
  </div>
</div>
        </form>
    </div>
</div>
<script type="text/javascript">
jQuery(function($){
        <?php if(isset($user->card->expiration_month)): ?>$('[data-openpay-card="expiration_month"]').val('<?=$user->card->expiration_month?>');<?php endif; ?>
        <?php if(isset($user->card->expiration_year)): ?>$('[data-openpay-card="expiration_year"]').val('<?=$user->card->expiration_year?>');<?php endif; ?>
});
</script>