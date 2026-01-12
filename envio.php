<?php
session_start();
header("Content-Type: application/json; charset=utf-8");

// ===================== ENTRADA ===================== //
$postdata = file_get_contents("php://input");
$request  = json_decode($postdata);

if (!isset($request->function) || !function_exists($request->function)) {
    echo json_encode(["error" => "Función no encontrada"]);
    exit;
}
call_user_func($request->function, $request);

// ===================== CONEXIÓN ===================== //
function getConnection() {
    try {
        include "Aorta.php"; // Debe definir $conn = new PDO(...)
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $conn;
    } catch (PDOException $e) {
        echo json_encode(["error" => "Error de conexión: " . $e->getMessage()]);
        exit;
    }
}

// ===================== UTILIDADES ===================== //
function querySingleRow($sql, $params = []) {
    $conn = getConnection();
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $conn = null;
    return $row ?: null;
}
function queryAll($sql, $params = []) {
    $conn = getConnection();
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $conn = null;
    return $rows ?: [];
}

// ===================== ALEGRA API ===================== //
function callAlegraAPI($endpoint, $method = "GET", $data = null) {
    $user  = getenv('ALEGRA_USER') ?: 'mariamargaritavides@gmail.com';
    $token = getenv('ALEGRA_TOKEN') ?: '7d63006638f9d02ec1bd';
 

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.alegra.com/api/v1/$endpoint");
    curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $token);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    $headers = ['Accept: application/json'];
    if ($data !== null) {
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($jsonData);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $err      = curl_error($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) return ["error" => $err];
    $json = json_decode($response, true);
    if ($status >= 400) {
        return ["error" => "HTTP $status", "detalle" => ($json ?: $response)];
    }
    return $json;
}

// ===================== CLIENTES ===================== //
function consultarClienteWarePorId($terceroId) {
    $sql = "SELECT * FROM trc_tercero WHERE tercero_id = ?";
    return querySingleRow($sql, [$terceroId]) ?? null;
}
function consultarClienteWarePorIdentificacion($identificacion) {
    $sql = "SELECT * FROM trc_tercero WHERE identificacion = ?";
    return querySingleRow($sql, [$identificacion]) ?? null;
}
/** Resuelve cliente aunque id_cliente venga como tercero_id o como identificación */
function resolverClienteWareFlexible($valorIdCliente) {
    if ($valorIdCliente === null || $valorIdCliente === '') return null;

    if (ctype_digit((string)$valorIdCliente)) {
        $cli = consultarClienteWarePorId((int)$valorIdCliente);
        if ($cli) return $cli;
    }
    $cli = consultarClienteWarePorIdentificacion((string)$valorIdCliente);
    if ($cli) return $cli;

    if (ctype_digit((string)$valorIdCliente)) {
        $cli = consultarClienteWarePorIdentificacion((string)$valorIdCliente);
        if ($cli) return $cli;
    }
    return null;
}

function consultarClienteAlegra($idTerceroWare) {
    $sql = "SELECT id_alegra FROM cliente_alegra WHERE id_tercero_ware = ?";
    $row = querySingleRow($sql, [$idTerceroWare]);
    return $row['id_alegra'] ?? 0;
}

/** Crea contacto en Alegra e inserta mapeo correcto en cliente_alegra (sin IVA) */
function creandoClienteAlegra($datos) {
    if (!is_array($datos)) return "Datos de cliente inválidos";

    $nombre    = $datos['primer_nombre'] ?? '';
    $ap1       = $datos['primer_apellido'] ?? '';
    $ap2       = $datos['segundo_apellido'] ?? '';
    $full      = trim(($datos['full_nombre'] ?? '') ?: trim("$nombre $ap1 $ap2"));
    $ident     = $datos['identificacion'] ?? '';
    $direccion = $datos['direccion'] ?? '';
    $correo    = $datos['email'] ?? '';
    $tel       = $datos['telefono'] ?? '';
    $idTercero = $datos['tercero_id'] ?? null;

    // Payload “flat”
    $cliente = [
        "name"           => $full ?: $ident,
        "identification" => $ident,
        "email"          => $correo,
        "phonePrimary"   => $tel,
        "mobile"         => $tel,
        "type"           => ["client"],
        "address"        => [
            "address"    => $direccion,
            "city"       => "Barranquilla",
            "department" => "Atlántico",
            "country"    => "Colombia"
        ]
    ];

    $response = callAlegraAPI("contacts", "POST", $cliente);
    if (!isset($response['id'])) {
        return ["error" => "Error al crear cliente en Alegra", "detalle" => $response];
    }

    $conn = getConnection();
    $stmt = $conn->prepare("
        INSERT INTO cliente_alegra (identificacion, id_tercero_ware, id_alegra)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE identificacion=VALUES(identificacion), id_alegra=VALUES(id_alegra)
    ");
    $stmt->execute([$ident, $idTercero, (int)$response['id']]);
    $conn = null;

    return (int)$response['id'];
}

// ===================== PRODUCTOS ===================== //
function consultarProductoAlegra($codigoWare) {
    $sql = "SELECT codigo_alegra FROM prod_alegra_ware WHERE codigo_ware = ?";
    $row = querySingleRow($sql, [$codigoWare]);
    return $row['codigo_alegra'] ?? 0;
}
function consultarArticuloWare($codigoWare) {
    $sql = "SELECT codigo, nombre, precio_venta FROM prod_productos WHERE codigo = ?";
    return querySingleRow($sql, [$codigoWare]) ?? null;
}
function guardar_producto($producto) {
    if (!is_array($producto) || !isset($producto['codigo'])) {
        return "Datos de producto inválidos";
    }
    $data = [
        "name"      => $producto['nombre'] ?? ("Producto " . $producto['codigo']),
        "inventory" => ["unit" => "unit"],
        "price"     => (float)($producto['precio_venta'] ?? 0)
    ];
    $response = callAlegraAPI("items", "POST", $data);
    if (!isset($response['id'])) return ["error" => "Error al crear producto en Alegra", "detalle" => $response];

    $conn = getConnection();
    $stmt = $conn->prepare("INSERT INTO prod_alegra_ware (codigo_ware, codigo_alegra) VALUES (?, ?)");
    $stmt->execute([$producto['codigo'], (int)$response['id']]);
    $conn = null;

    return (int)$response['id'];
}

// ===================== FACTURAS (SIN IVA) ===================== //
function creandoFactura($request) {
    $doc = $request->doc ?? null;
    if (!$doc) {
        echo json_encode(["error" => "Documento no especificado"]);
        return;
    }

    $conn = getConnection();

    // ================= VALIDAR SI YA FUE ENVIADA =================
    $stmt = $conn->prepare("
        SELECT *
        FROM dian_factura_alegra 
        WHERE numeroFactura = ?
        LIMIT 1
    ");
    $stmt->execute([$doc]);

    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        echo json_encode([
            "error"   => "Factura ya enviada",
            "detalle" => "La factura $doc ya fue enviada anteriormente a Alegra"
        ]);
        return;
    }

    // ================= ENCABEZADO =================
    $enc = querySingleRow(
        "SELECT tipo, numero, id_cliente 
         FROM venta_encabezado 
         WHERE numero = ? AND tipo = 'FV'",
        [$doc]
    );

    if (!$enc) {
        echo json_encode(["error" => "No existe encabezado FV para $doc"]);
        return;
    }

    // ================= DETALLES (SOLO BODEGA 1) =================
    $stmt = $conn->prepare("
        SELECT 
            vd.id_detalle_venta,
            vd.numero, 
            vd.id_producto, 
            vd.cantidad, 
            vd.descuento, 
            vd.porcentaje_iva, 
            vd.iva, 
            vd.porcentaje_rentabilidad, 
            vd.rentabilidad, 
            vd.precio_sin_iva, 
            vd.total, 
            vd.estado, 
            p.nombre, 
            p.bodega_id, 
            ve.tipo, 
            ve.id_cliente, 
            ve.fecha_registro
        FROM venta_detalles vd
        INNER JOIN prod_productos p 
            ON vd.id_producto = p.codigo
        INNER JOIN venta_encabezado ve 
            ON vd.numero = ve.numero AND ve.tipo = vd.tipo
        WHERE vd.numero = ?
          AND ve.tipo = 'FV'
          AND p.bodega_id = 1
    ");
    $stmt->execute([$doc]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) === 0) {
        echo json_encode([
            "error"   => "Factura no permitida",
            "detalle" => "La factura $doc no contiene productos de la bodega 1"
        ]);
        return;
    }

    // ================= CLIENTE =================
    $clienteIdCrudo = $rows[0]['id_cliente'] ?? null;
    $clienteWare    = resolverClienteWareFlexible($clienteIdCrudo);

    if (!$clienteWare) {
        echo json_encode([
            "error" => "Cliente no encontrado en Ware",
            "id_cliente_origen" => $clienteIdCrudo
        ]);
        return;
    }

    $clienteIdWare   = $clienteWare['tercero_id'];
    $idClienteAlegra = consultarClienteAlegra($clienteIdWare);

    if ($idClienteAlegra == 0) {
        $idClienteAlegra = creandoClienteAlegra($clienteWare);
        if (!is_numeric($idClienteAlegra)) {
            echo json_encode($idClienteAlegra);
            return;
        }
    }

    // ================= ÍTEMS =================
    $items = [];
    foreach ($rows as $row) {
        $codigoProducto = $row['id_producto'];

        $productoId = consultarProductoAlegra($codigoProducto);
        if ($productoId == 0) {
            $p = consultarArticuloWare($codigoProducto);
            if ($p) {
                $productoId = guardar_producto($p);
                if (!is_numeric($productoId)) continue;
            } else continue;
        }

        $qty  = max(1, (int)$row['cantidad']);
        $unit = (float)$row['precio_sin_iva'];
        if ($unit <= 0) {
            $unit = $qty > 0 ? ((float)$row['total'] / $qty) : 0;
        }

        $items[] = [
            "id"          => (int)$productoId,
            "quantity"    => $qty,
            "discount"    => 0,
            "description" => $row['nombre'],
            "price"       => $unit
        ];
    }

    if (empty($items)) {
        echo json_encode(["error" => "No fue posible construir los ítems para Alegra"]);
        return;
    }

    // ================= ENVÍO A ALEGRA =================
    $payload = [
        "date"          => date("Y-m-d"),
        "dueDate"       => date("Y-m-d"),
        "client"        => (int)$idClienteAlegra,
        "items"         => $items,
        "paymentForm"   => "CASH",
        "paymentMethod" => "CASH",
        "anotation"     => "Factura WarePOS #{$doc}",
        "stamp"         => ["generateStamp" => true]
    ];

    $res = callAlegraAPI("invoices", "POST", $payload);

    if (isset($res['id'])) {
        $stmt = $conn->prepare("
            INSERT INTO dian_factura_alegra
            (fechaRegistroAlegra, numeroFactura, itemAlegra, fe, stampCUFE, stampBarCodeContent)
            VALUES (NOW(), ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $doc,
            $res['id'],
            $res['numberTemplate']['fullNumber'] ?? null,
            $res['stamp']['cufe'] ?? null,
            $res['stamp']['barCodeContent'] ?? null
        ]);

        echo json_encode([
            "success"   => true,
            "mensaje"   => "Factura creada correctamente en Alegra",
            "id_alegra" => $res['id'],
            "numero"    => $res['numberTemplate']['fullNumber'] ?? null,
            "CUFE"      => $res['stamp']['cufe'] ?? null
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            "error"   => "No se pudo crear la factura en Alegra",
            "detalle" => $res
        ]);
    }

    $conn = null;
}


?>
