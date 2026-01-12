import requests
import mysql.connector
from mysql.connector import Error

DB_CONFIG = {
    "host": "localhost",
    "user": "root",
    "password": "",
    "database": "ware_pos"
}

API_URL = "http://localhost/send/envio.php"
TIMEOUT = 60

try:
    conn = mysql.connector.connect(**DB_CONFIG)
    cursor = conn.cursor(dictionary=True)

    cursor.execute("""
        SELECT ve.numero
        FROM venta_encabezado ve
        LEFT JOIN dian_factura_alegra dfa 
            ON ve.numero = dfa.numeroFactura
        WHERE ve.tipo = 'FV'
          AND dfa.numeroFactura IS NULL
        ORDER BY ve.fecha_registro ASC
    """)

    facturas = cursor.fetchall()
    cursor.close()
    conn.close()

    if not facturas:
        print("✅ No hay facturas pendientes")
        exit()

    for row in facturas:
        doc = str(row["numero"])

        payload = {
            "function": "creandoFactura",
            "doc": doc
        }

        try:
            response = requests.post(
                API_URL,
                json=payload,
                timeout=TIMEOUT
            )

            print(f"\n📤 Enviando factura: {doc}")
            print(f"📡 HTTP {response.status_code}")
            print(response.text)

        except requests.exceptions.RequestException as e:
            print(f"❌ Error enviando {doc}: {e}")

except Error as e:
    print(f"❌ Error MySQL: {e}")
