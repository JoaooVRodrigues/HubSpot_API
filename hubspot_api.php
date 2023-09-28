<?php

use Illuminate\Database\PDO\SqlServerDriver;

// Obtém o tempo para início da busca de dados
$agora = time();
$TempoDeBusca = strtotime('-1 year', $agora);
$TempoDeBusca = $TempoDeBusca * 1000; // converte para micro-segundos

// Configurações do banco de dados
$servername = 'SERVER_NAME';
$username = 'USER_NAME';
$password = 'PASSWORD';
$dbname = 'DATABASE_NAME';


// Conexão com o banco de dados
try {
    // Conexão com o banco de dados
    $conn = new PDO("sqlsrv:Server=$servername;Database=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException $e) {
    echo 'Erro na conexão com o banco de dados: ' . $e->getMessage();
}

// -------------------------------------------------------------------------------

// Dados de autenticação do HubSpot
$accessToken = '';

// Array de autenticação
$auth = 'Authorization: Bearer ';

// URL da API do HubSpot para obter dados do cliente
$url = 'https://api.hubapi.com/crm/v3/objects/deals?properties=cnpj&properties=distribuidora&properties=dealname&properties=dealstage&properties=hs_lastmodifieddate';

// -------------------------------------------------------------------------------

function varredura_deals($auth, $url, $conn, $TempoDeBusca)
{
    
    // Configuração da requisição
    $curl = curl_init();

    curl_setopt_array($curl, array(
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'GET',
    CURLOPT_HTTPHEADER => array(
        $auth
    ),
    ));

    $response = json_decode(curl_exec($curl), true);
    curl_close($curl);

    // ---------------------------------------------

    $filtro_status_propectos = "15964616, 15964617, 24710273, 45625946, 17261053, 15965076, 47050056, decisionmakerboughtin, contractsent, 47087988, 32722257";
    $filtro_status_cliente = "11184572, closedwon, 60501678";

    // Captura de dados dos deals desta página
    $deals = $response['results'];
    foreach($deals as $deal)
    {
        $id = $deal['id'];
        $data = $deal['properties'];
        $last_modified_date = $data['hs_lastmodifieddate'];

        if ($last_modified_date > $TempoDeBusca)
        {
            if(isset($data['cnpj'])){
                $cnpj = $data['cnpj'];
            } else{
                $cnpj = "";
            }
            if(isset($data['distribuidora'])){
                $distribuidora = $data['distribuidora'];
            } else{
                $distribuidora = "";
            }

            $status_cliente = $data['dealstage'];

            // Configura filtro de STATUS do DEAL: prospecto = 0, cliente = 1
            if(str_contains($filtro_status_propectos, $status_cliente))
            {
                $status_cliente = 0;
                pesquisa_companies_associados($auth, $id, $conn, $status_cliente, $cnpj, $distribuidora, $last_modified_date);

            } elseif(str_contains($filtro_status_cliente, $status_cliente))
            {
                $status_cliente = 1;
                pesquisa_companies_associados($auth, $id, $conn, $status_cliente, $cnpj, $distribuidora, $last_modified_date);
            }
        }

        
        
    }

    // Fim da página -> Vai para a próxima, se existir
    if (isset($response['paging']['next']['link'])){
        $next_page = $response['paging']['next']['link'];
        varredura_deals($auth, $next_page, $conn, $TempoDeBusca);
    } else{
        echo "Todos os dados Arquivados/Atualizados<br>";
    }

}

function pesquisa_companies_associados($auth, $deal_id, $conn, $status_cliente, $Cgc, $Distribuidora, $last_modified_date)
{
    // url do Escopo da pesquisa
    $url = 'https://api.hubapi.com/crm/v3/objects/companies/search';

    $ch = curl_init();

    // Configura filtro
    $filter_str = '{
        "filterGroups":[
        {
            "filters":[
            {
                "propertyName": "associations.deal",
                "operator": "EQ",
                "value": [VALUE]
            }
            ]
        }
        ]
    }\'';
    $value = $deal_id;
    $filter_str = str_replace("[VALUE]", $value, $filter_str);

    curl_setopt_array($ch, array(
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS =>$filter_str,
    CURLOPT_HTTPHEADER => array(
        'Content-Type: application/json',
        $auth
    ),
    ));

    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    //------------------------------------------

    if($response['total'] > 0)
    {
        $companies = $response['results'];
        foreach($companies as $company)
        {
            $data = $company['properties'];

            $Nome_abrev = $data['name'];
            if(str_contains($Nome_abrev, "'")){
                $Nome_abrev = str_replace("'", "", $Nome_abrev);
            }
            $hubspot_guid = $deal_id;

            // Verifica se os dados já existem na base de dados
            $sql = "SELECT * FROM clientes WHERE hubspot_guid = :hubspot_guid";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':hubspot_guid', $hubspot_guid);
            $stmt->execute();

            
            $results = $stmt->fetchAll();
            if (count($results) > 0) {
                // Os dados já existem, então atualiza-os na DB
                $sql = "UPDATE clientes SET status_cliente = :status_cliente, Nome_abrev = :Nome_abrev, Cgc = :Cgc WHERE hubspot_guid = :hubspot_guid";
                $stmt = $conn->prepare($sql);
                $stmt->bindParam(':status_cliente', $status_cliente);
                $stmt->bindParam(':Nome_abrev', $Nome_abrev);
                $stmt->bindParam(':Cgc', $Cgc);
                // $stmt->bindParam(':Distribuidora', $Distribuidora);
                $stmt->bindParam(':hubspot_guid', $hubspot_guid);
                $stmt->execute();
            }
            else {
                // Os dados não existem, então adiciona-os a DB
                $sql = "INSERT INTO clientes (hubspot_guid, Nome_abrev, status_cliente, Cgc) VALUES (:hubspot_guid, :Nome_abrev, :status_cliente, :Cgc)";
                $stmt = $conn->prepare($sql);
                $stmt->bindParam(':hubspot_guid', $hubspot_guid);
                $stmt->bindParam(':Nome_abrev', $Nome_abrev);
                $stmt->bindParam(':Cgc', $Cgc);
                // $stmt->bindParam(':Distribuidora', $Distribuidora);
                $stmt->bindParam(':status_cliente', $status_cliente);
                $stmt->execute();
                
                // Obter o último valor de identidade inserido
                $lastInsertedId = $conn->lastInsertId();
                
                pesquisa_contactos_associados($auth, $hubspot_guid, $conn, $lastInsertedId);
            }
        }
    }

    return;

}

function pesquisa_contactos_associados($auth, $hubspot_guid, $conn, $id_cliente)
{
    // url do Escopo da pesquisa
    $url = 'https://api.hubapi.com/crm/v3/objects/contacts/search';

    $ch = curl_init();

    // Configura filtro
    $filter_str = '{
        "filterGroups":[
        {
            "filters":[
            {
                "propertyName": "associations.deal",
                "operator": "EQ",
                "value": [VALUE]
            }
            ]
        }
        ]
    }\'';
    $value = $hubspot_guid;
    $filter_str = str_replace("[VALUE]", $value, $filter_str);

    curl_setopt_array($ch, array(
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS =>$filter_str,
    CURLOPT_HTTPHEADER => array(
        'Content-Type: application/json',
        $auth
    ),
    ));

    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    // Verifica se existe contato associado a tal company
    if($response['total'] > 0)
    {
        $contacts = $response['results'];
        foreach($contacts as $contact)
        {
            $data = $contact['properties'];

            $E_mail = $data['email'];
            $firstname = $data['firstname'];
            $lastname = $data['lastname'];

            // Corrige bug de dados mal inseridos -> '
            if(str_contains($lastname, "'")){
                $lastname = str_replace("'", "", $lastname);
            } else if($lastname == null){
                $lastname = "";
            }
            $Nome = $firstname." ".$lastname;

            // Os dados não existem, então adiciona-os a DB
            $sql = "INSERT INTO contatos (id_cliente, Nome, E_mail, hubspot_guid) VALUES (:id_cliente, :Nome, :E_mail, :hubspot_guid)";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':id_cliente', $id_cliente);
            $stmt->bindParam(':Nome', $Nome);
            $stmt->bindParam(':E_mail', $E_mail);
            $stmt->bindParam(':hubspot_guid', $hubspot_guid);
            $stmt->execute();
        }
    }

    return;
}

// primeira interação
varredura_deals($auth, $url, $conn, $TempoDeBusca);

// Fecha a conexão com o banco de dados
$conn = null;

?>