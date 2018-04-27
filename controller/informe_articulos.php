<?php
/*
 * This file is part of facturacion_base
 * Copyright (C) 2014-2017  Carlos Garcia Gomez  neorazorx@gmail.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

require_once 'plugins/facturacion_base/extras/fbase_controller.php';
require_once 'extras/xlsxwriter.class.php';
require_model('cpc_fpdf');

class informe_articulos extends fbase_controller
{

    public $agente;
    public $almacenes;
    public $articulo;
    public $cantidades;
    public $codagente;
    public $codalmacen;
    public $codfamilia;
    public $codimpuesto;
    public $desde;
    public $documento;
    public $familia;
    public $hasta;
    public $impuesto;
    public $minimo;
    public $offset;
    public $pestanya;
    public $referencia;
    public $resultados;
    public $sin_vender;
    public $stats;
    public $stock;
    public $tipo_stock;
    public $top_ventas;
    public $top_compras;
    public $url_recarga;
    public $objtarifa;
    public $familiaaux;
    public $tarifaaux;
    private $recalcular_stock;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Artículos', 'informes');
    }

    protected function private_core()
    {
        parent::private_core();
        $this->agente = new agente();
        $almacen = new almacen();
        $this->almacenes = $almacen->all();
        $this->articulo = new articulo();
        $this->familia = new familia();
        $this->impuesto = new impuesto();
        $this->recalcular_stock = new recalcular_stock();
        $this->resultados = array();
        $this->stock = new stock();
        $this->url_recarga = FALSE;

        $this->objtarifa = new tarifa();

        $this->ini_filters();

        if (isset($_REQUEST['buscar_referencia'])) {
            $this->buscar_referencia();
        } else if ($this->pestanya == 'stats') {
            $this->stats = $this->stats();

            /// forzamos la comprobación de las tablas de lineas de facturas
            new linea_factura_cliente();
            new linea_factura_proveedor();

            $this->top_ventas = $this->top_articulo_faccli();
            $this->sin_vender = $this->sin_vender();
            $this->top_compras = $this->top_articulo_facpro();
        } else if ($this->pestanya == 'stock') {
            /// forzamos la comprobación de la tabla stock
            new stock();

            $this->tipo_stock = 'todo';
            if (isset($_GET['tipo'])) {
                $this->tipo_stock = $_GET['tipo'];
            } else if (isset($_GET['recalcular'])) {
                $this->recalcular_stock();
            }

            if ($this->tipo_stock == 'reg') {
                /// forzamos la comprobación de la tabla stocks
                new regularizacion_stock();

                $this->resultados = $this->regularizaciones_stock($this->offset);
            } else if (isset($_GET['download'])) {
                $this->download_stock();
            } else
                $this->resultados = $this->stock($this->offset, $this->tipo_stock);
        } else if ($this->pestanya == 'impuestos') {
            $this->cambia_impuesto();
        } else if ($this->pestanya == 'varios') {
            if (isset($_POST['informe'])) {
                if ($_POST['informe'] == 'listadomov') {
                    $this->informe_movimientos();
                } else if ($_POST['informe'] == 'facturacion') {
                    $this->informe_facturacion();
                } else if ($_POST['informe'] == 'ventascli') {
                    $this->informe_ventascli();
                }
            }
        } elseif ($this->pestanya == 'lista') {

            if (isset($_POST['familia'])) {
                $this->familiaaux = $_POST['familia'];
            }

            if (isset($_POST['tarifa'])) {
                $this->tarifaaux = $_POST['tarifa'];
            }


            if (isset($_POST['tipolist'])) {
                if ($_POST['tipolist'] == 'pdflist') {
                    $this->generar_pdf_list();
                }
            }
        }
    }

    private function ini_filters()
    {
        $this->pestanya = 'stats';
        if (isset($_GET['tab'])) {
            $this->pestanya = $_GET['tab'];
        }

        $this->cantidades = FALSE;
        if (isset($_POST['cantidades'])) {
            $this->cantidades = ($_POST['cantidades'] == 'TRUE');
        }

        $this->codagente = '';
        if (isset($_REQUEST['codagente'])) {
            $this->codagente = $_REQUEST['codagente'];
        }

        $this->codalmacen = '';
        if (isset($_REQUEST['codalmacen'])) {
            $this->codalmacen = $_REQUEST['codalmacen'];
        }

        $this->codfamilia = '';
        if (isset($_REQUEST['codfamilia'])) {
            $this->codfamilia = $_REQUEST['codfamilia'];
        }

        $this->codimpuesto = '';
        if (isset($_REQUEST['codimpuesto'])) {
            $this->codimpuesto = $_REQUEST['codimpuesto'];
        }

        $this->desde = Date('01-m-Y');
        if (isset($_POST['desde'])) {
            $this->desde = $_POST['desde'];
        }

        $this->documento = 'facturascli';
        if (isset($_POST['documento'])) {
            $this->documento = $_POST['documento'];
        }

        $this->hasta = Date('t-m-Y');
        if (isset($_POST['hasta'])) {
            $this->hasta = $_POST['hasta'];
        }

        $this->minimo = '';
        if (isset($_REQUEST['minimo'])) {
            $this->minimo = $_REQUEST['minimo'];
        }

        $this->offset = 0;
        if (isset($_GET['offset'])) {
            $this->offset = intval($_GET['offset']);
        }

        $this->referencia = '';
        if (isset($_POST['referencia'])) {
            $this->referencia = $_POST['referencia'];
        } else if (isset($_GET['ref'])) {
            $this->referencia = $_GET['ref'];
        }
    }

    private function recalcular_stock()
    {
        $articulo = new articulo();
        $continuar = FALSE;
        foreach ($articulo->all($this->offset, 25) as $art) {
            $this->calcular_stock_real($art);
            $continuar = TRUE;
            $this->offset++;
        }

        if ($continuar) {
            $this->new_message('Recalculando stock de artículos... (' . $this->offset . ') &nbsp; <i class="fa fa-refresh fa-spin"></i>');
            $this->url_recarga = $this->url() . '&tab=stock&recalcular=TRUE&offset=' . $this->offset;
        } else {
            $this->new_advice('Finalizado &nbsp; <span class="glyphicon glyphicon-ok" aria-hidden="true"></span>');
            $this->offset = 0;
        }
    }

    private function download_stock()
    {
        $header = array(
            'almacen' => 'string',
            'referencia' => '@',
            'descripcion' => 'string',
            'stock' => '0',
            'stockmin' => '0',
            'stockmax' => '0'
        );

        $rows = array();
        $offset = 0;
        $resultados = $this->stock($offset, $this->tipo_stock);
        while (count($resultados) > 0) {
            foreach ($resultados as $res) {
                $rows[] = array(
                    $res['codalmacen'], $res['referencia'], fs_fix_html($res['descripcion']),
                    $res['cantidad'], $res['stockmin'], $res['stockmax']
                );
                $offset++;
            }

            $resultados = $this->stock($offset, $this->tipo_stock);
        }

        $this->generar_archivo('Stock', $header, $rows, $_GET['download']);
    }

    private function cambia_impuesto()
    {
        if (isset($_POST['new_codimpuesto'])) {
            if ($_POST['new_codimpuesto'] != '') {
                $sql = "UPDATE articulos SET codimpuesto = " . $this->impuesto->var2str($_POST['new_codimpuesto']);
                if ($this->codimpuesto == '') {
                    $sql .= " WHERE codimpuesto IS NULL";
                } else {
                    $sql .= " WHERE codimpuesto = " . $this->impuesto->var2str($this->codimpuesto);
                }

                if ($this->db->exec($sql)) {
                    $this->new_message('cambios aplicados correctamente.');
                } else {
                    $this->new_error_msg('Error al aplicar los cambios.');
                }
            }
        }

        /// buscamos en la tabla
        $sql = "SELECT * FROM articulos";
        if ($this->codimpuesto == '') {
            $sql .= " WHERE codimpuesto IS NULL";
        } else {
            $sql .= " WHERE codimpuesto = " . $this->impuesto->var2str($this->codimpuesto);
        }

        $this->resultados = array();
        $data = $this->db->select_limit($sql . ' ORDER BY referencia ASC', 1000, 0);
        if ($data) {
            foreach ($data as $d) {
                $this->resultados[] = new articulo($d);
            }
        }
    }

    private function stats()
    {
        $stats = array(
            'total' => 0,
            'con_stock' => 0,
            'bloqueados' => 0,
            'publicos' => 0,
            'factualizado' => Date('d-m-Y', strtotime(0))
        );

        $sql = "SELECT GREATEST( COUNT(referencia), 0) as art,"
            . " GREATEST( SUM(case when stockfis > 0 then 1 else 0 end), 0) as stock,"
            . " GREATEST( SUM(case when bloqueado then 1 else 0 end), 0) as bloq,"
            . " GREATEST( SUM(case when publico then 1 else 0 end), 0) as publi,"
            . " MAX(factualizado) as factualizado FROM articulos;";

        $aux = $this->db->select($sql);
        if ($aux) {
            $stats['total'] = intval($aux[0]['art']);
            $stats['con_stock'] = intval($aux[0]['stock']);
            $stats['bloqueados'] = intval($aux[0]['bloq']);
            $stats['publicos'] = intval($aux[0]['publi']);
            $stats['factualizado'] = Date('d-m-Y', strtotime($aux[0]['factualizado']));
        }

        return $stats;
    }

    private function top_articulo_faccli()
    {
        /// buscamos el resultado en caché
        $toplist = $this->cache->get_array('faccli_top_articulos');
        if (!$toplist || isset($_POST['desde'])) {
            $toplist = array();
            $articulo = new articulo();
            $sql = "SELECT l.referencia, SUM(l.cantidad) as unidades, SUM(l.pvptotal/f.tasaconv) as total"
                . " FROM lineasfacturascli l, facturascli f"
                . " WHERE l.idfactura = f.idfactura AND l.referencia IS NOT NULL"
                . " AND f.fecha >= " . $articulo->var2str($this->desde)
                . " AND f.fecha <= " . $articulo->var2str($this->hasta)
                . " GROUP BY referencia"
                . " ORDER BY unidades DESC";

            $lineas = $this->db->select_limit($sql, FS_ITEM_LIMIT, 0);
            if ($lineas) {
                foreach ($lineas as $l) {
                    $art0 = $articulo->get($l['referencia']);
                    if ($art0) {
                        $toplist[] = array(
                            'articulo' => $art0,
                            'unidades' => floatval($l['unidades']),
                            'total' => $this->euro_convert(floatval($l['total'])),
                            'beneficio' => $this->euro_convert(floatval($l['total'])) - ( floatval($l['unidades']) * $art0->preciocoste() )
                        );
                    }
                }
            }

            /// guardamos los resultados en caché
            $this->cache->set('faccli_top_articulos', $toplist, 300);
        }

        return $toplist;
    }

    private function sin_vender()
    {
        $toplist = $this->cache->get_array('top_articulos_sin_vender');
        if (!$toplist) {
            $articulo = new articulo();
            $sql = "select * from (select a.*"
                . " from "
                . " articulos a "
                . " left join (select lf.referencia"
                . " from lineasfacturascli lf, facturascli f"
                . " where"
                . " lf.idfactura=f.idfactura and"
                . " lf.referencia is not null and"
                . " f.fecha >= " . $articulo->var2str(Date('1-1-Y'))
                . " group by lf.referencia) as f1 on a.referencia=f1.referencia"
                . " where"
                . " f1.referencia is null order by a.stockfis desc) a";

            $lineas = $this->db->select_limit($sql, FS_ITEM_LIMIT, 0);
            if ($lineas) {
                foreach ($lineas as $l) {
                    $toplist[] = new articulo($l);
                }
            }

            /// guardamos los resultados en caché
            $this->cache->set('top_articulos_sin_vender', $toplist);
        }

        return $toplist;
    }

    private function top_articulo_facpro()
    {
        $toplist = $this->cache->get('facpro_top_articulos');
        if (!$toplist || isset($_POST['desde'])) {
            $articulo = new articulo();
            $sql = "SELECT l.referencia, SUM(l.cantidad) as compras FROM lineasfacturasprov l, facturasprov f"
                . " WHERE l.idfactura = f.idfactura AND l.referencia IS NOT NULL"
                . " AND f.fecha >= " . $articulo->var2str($this->desde)
                . " AND f.fecha <= " . $articulo->var2str($this->hasta)
                . " GROUP BY referencia"
                . " ORDER BY compras DESC";

            $lineas = $this->db->select_limit($sql, FS_ITEM_LIMIT, 0);
            if ($lineas) {
                foreach ($lineas as $l) {
                    $art0 = $articulo->get($l['referencia']);
                    if ($art0) {
                        $toplist[] = array($art0, intval($l['compras']));
                    }
                }
            }

            /// guardamos los resultados en caché
            $this->cache->set('facpro_top_articulos', $toplist);
        }

        return $toplist;
    }

    private function stock($offset = 0, $tipo = 'todo')
    {
        $slist = array();

        $sql = "SELECT codalmacen,s.referencia,a.descripcion,s.cantidad,a.stockmin,a.stockmax"
            . " FROM stocks s, articulos a WHERE s.referencia = a.referencia";

        if ($tipo == 'min') {
            $sql .= " AND s.cantidad < a.stockmin";
        } else if ($tipo == 'max') {
            $sql .= " AND a.stockmax > 0 AND s.cantidad > a.stockmax";
        }

        if ($this->codalmacen) {
            $sql .= " AND s.codalmacen = " . $this->empresa->var2str($this->codalmacen);
        }

        $sql .= " ORDER BY referencia ASC";

        $data = $this->db->select_limit($sql, FS_ITEM_LIMIT, $offset);
        if ($data) {
            foreach ($data as $d) {
                $slist[] = $d;
            }
        }

        return $slist;
    }

    private function regularizaciones_stock($offset = 0)
    {
        $slist = array();

        $sql = "SELECT s.codalmacen,s.referencia,a.descripcion,r.cantidadini,r.cantidadfin,r.nick,r.motivo,r.fecha,r.hora "
            . "FROM stocks s, articulos a, lineasregstocks r WHERE r.idstock = s.idstock AND s.referencia = a.referencia";
        if ($this->codalmacen) {
            $sql .= " AND codalmacen = " . $this->empresa->var2str($this->codalmacen);
        }
        $sql .= " ORDER BY fecha DESC, hora DESC";

        $data = $this->db->select_limit($sql, FS_ITEM_LIMIT, $offset);
        if ($data) {
            foreach ($data as $d) {
                $slist[] = $d;
            }
        }

        return $slist;
    }

    public function anterior_url()
    {
        $url = '';
        $extra = '&tab=stock&tipo=' . $this->tipo_stock . '&codalmacen=' . $this->codalmacen;

        if ($this->offset > 0) {
            $url = $this->url() . "&offset=" . ($this->offset - FS_ITEM_LIMIT) . $extra;
        }

        return $url;
    }

    public function siguiente_url()
    {
        $url = '';
        $extra = '&tab=stock&tipo=' . $this->tipo_stock . '&codalmacen=' . $this->codalmacen;

        if (count($this->resultados) == FS_ITEM_LIMIT) {
            $url = $this->url() . "&offset=" . ($this->offset + FS_ITEM_LIMIT) . $extra;
        }

        return $url;
    }

    private function buscar_referencia()
    {
        /// desactivamos la plantilla HTML
        $this->template = FALSE;

        $articulo = new articulo();
        $json = array();
        foreach ($articulo->search($_REQUEST['buscar_referencia']) as $art) {
            $json[] = array('value' => $art->referencia . ' ' . $art->descripcion(60), 'data' => $art->referencia);
        }

        header('Content-Type: application/json');
        echo json_encode(array('query' => $_REQUEST['buscar_referencia'], 'suggestions' => $json));
    }

    private function informe_facturacion()
    {
        $sumar = 'pvptotal';
        if ($this->cantidades) {
            $sumar = 'cantidad';
        }

        $sql = "SELECT l.referencia,f.fecha,SUM(" . $sumar . ") as total"
            . " FROM " . $this->documento . " f, lineas" . $this->documento . " l"
            . " WHERE f.idfactura = l.idfactura"
            . " AND referencia IS NOT NULL AND referencia != ''"
            . " AND fecha >= " . $this->empresa->var2str($this->desde)
            . " AND fecha <= " . $this->empresa->var2str($this->hasta);

        if (is_numeric($this->minimo)) {
            $sql .= " AND " . $sumar . " >= " . $this->empresa->var2str($this->minimo);
        }

        if ($this->codfamilia != '') {
            $sql .= " AND referencia IN (SELECT referencia FROM articulos"
                . " WHERE codfamilia IN (";
            $coma = '';
            foreach ($this->get_subfamilias($this->codfamilia) as $fam) {
                $sql .= $coma . $this->empresa->var2str($fam);
                $coma = ',';
            }
            $sql .= "))";
        }

        $sql .= " GROUP BY referencia,fecha ORDER BY fecha DESC";

        $data = $this->db->select($sql);
        if ($data) {
            $this->template = FALSE;

            header("content-type:application/csv;charset=UTF-8");
            header("Content-Disposition: attachment; filename=\"informe_facturacion.csv\"");
            echo "referencia;descripcion;año;ene;feb;mar;abr;may;jun;jul;ago;sep;oct;nov;dic;total;%VAR\n";

            $stats = array();
            foreach ($data as $d) {
                $anyo = date('Y', strtotime($d['fecha']));
                $mes = date('n', strtotime($d['fecha']));
                if (!isset($stats[$d['referencia']][$anyo])) {
                    $stats[$d['referencia']][$anyo] = array(
                        1 => 0,
                        2 => 0,
                        3 => 0,
                        4 => 0,
                        5 => 0,
                        6 => 0,
                        7 => 0,
                        8 => 0,
                        9 => 0,
                        10 => 0,
                        11 => 0,
                        12 => 0,
                        13 => 0,
                        14 => 0
                    );
                }

                $stats[$d['referencia']][$anyo][$mes] += floatval($d['total']);
                $stats[$d['referencia']][$anyo][13] += floatval($d['total']);
            }

            $art0 = new articulo();
            foreach ($stats as $i => $value) {
                /// calculamos la variación
                $anterior = 0;
                foreach (array_reverse($value, TRUE) as $j => $value2) {
                    if ($anterior > 0) {
                        $value[$j][14] = ($value2[13] * 100 / $anterior) - 100;
                    }

                    $anterior = $value2[13];
                }

                foreach ($value as $j => $value2) {
                    $articulo = $art0->get($i);
                    if ($articulo) {
                        echo '"' . $i . '";"' . fs_fix_html($articulo->descripcion()) . '";' . $j;
                    } else {
                        echo '"' . $i . '";"";' . $j;
                    }

                    foreach ($value2 as $value3) {
                        echo ';' . number_format($value3, FS_NF0, ',', '');
                    }

                    echo "\n";
                }
                echo ";;;;;;;;;;;;;;;;\n";
            }
        } else {
            $this->new_message('Sin resultados.');
        }
    }

    private function get_subfamilias($cod)
    {
        $familias = array($cod);

        $data = $this->db->select("SELECT codfamilia,madre FROM familias WHERE madre = " . $this->empresa->var2str($cod) . ";");
        if ($data) {
            foreach ($data as $d) {
                foreach ($this->get_subfamilias($d['codfamilia']) as $subf) {
                    $familias[] = $subf;
                }
            }
        }

        return $familias;
    }

    /**
     * Recalcula el stock del artículo $articulo para cada almacén.
     * @param articulo $articulo
     */
    private function calcular_stock_real(&$articulo)
    {
        if ($articulo->nostock === FALSE) {
            foreach ($this->almacenes as $alm) {
                $total = 0;
                foreach ($this->recalcular_stock->get_movimientos($articulo->referencia, $alm->codalmacen) as $mov) {
                    $total = $mov['final'];
                }

                if (!$articulo->set_stock($alm->codalmacen, $total)) {
                    $this->new_error_msg('Error al recarcular el stock del artículo ' . $articulo->referencia
                        . ' en almacén ' . $alm->codalmacen . '.');
                }
            }
        }
    }

    private function informe_movimientos()
    {
        if ($this->codfamilia) {
            $familia = $this->familia->get($this->codfamilia);
            if ($familia) {
                foreach ($familia->get_articulos() as $art) {
                    foreach ($this->recalcular_stock->get_movimientos($art->referencia, $this->codalmacen, $this->desde, $this->hasta, $this->codagente) as $mov) {
                        $this->resultados[] = $mov;
                    }
                }
            } else {
                $this->new_advice('Familia no encontrada.');
            }
        } else if ($this->referencia == '') {
            $this->new_advice('Selecciona una referencia o una familia.');
        } else {
            $this->resultados = $this->recalcular_stock->get_movimientos($this->referencia, $this->codalmacen, $this->desde, $this->hasta, $this->codagente);
        }

        if (empty($this->resultados)) {
            $this->new_message('Sin resultados.');
        } else if ($_POST['generar'] != '') {
            $header = array(
                'Referencia' => '@',
                'Almacen' => 'string',
                'Documento' => 'string',
                'Cliente/Proveedor' => 'string',
                'Movimiento' => '0',
                'Precio' => 'price',
                'Descuento' => '0',
                'Cantidad' => '0',
                'Fecha' => 'date'
            );

            $ref = FALSE;
            $rows = array();
            foreach ($this->resultados as $value) {
                if (!$ref) {
                    $ref = $value['referencia'];
                } else if ($ref != $value['referencia']) {
                    $ref = $value['referencia'];
                    $rows[] = array('', '', '', '', '', '', '', '', '');
                }

                $rows[] = array(
                    $value['referencia'], $value['codalmacen'], $value['origen'],
                    fs_fix_html($value['clipro']), $value['movimiento'], $value['precio'],
                    $value['dto'], $value['final'], $value['fecha']
                );
            }

            $this->generar_archivo('Listado_movimientos', $header, $rows, $_POST['generar']);
        }
    }

    private function informe_ventascli()
    {
        $sql = "SELECT l.referencia,f.codcliente,f.fecha,SUM(l.cantidad) as total"
            . " FROM facturascli f, lineasfacturascli l"
            . " WHERE f.idfactura = l.idfactura AND l.referencia IS NOT NULL"
            . " AND f.fecha >= " . $this->empresa->var2str($_POST['desde'])
            . " AND f.fecha <= " . $this->empresa->var2str($_POST['hasta']);

        if ($this->referencia != '') {
            $sql .= " AND l.referencia = " . $this->empresa->var2str($this->referencia);
        } else if ($this->codfamilia != '') {
            $sql .= " AND l.referencia IN (SELECT referencia FROM articulos"
                . " WHERE codfamilia IN (";
            $coma = '';
            foreach ($this->get_subfamilias($this->codfamilia) as $fam) {
                $sql .= $coma . $this->empresa->var2str($fam);
                $coma = ',';
            }
            $sql .= "))";
        }

        if ($_POST['minimo'] != '') {
            $sql .= " AND l.cantidad > " . $this->empresa->var2str($_POST['minimo']);
        }

        $sql .= " GROUP BY l.referencia,f.codcliente,f.fecha ORDER BY l.referencia ASC, f.codcliente ASC, f.fecha DESC;";

        $data = $this->db->select($sql);
        if ($data) {
            $this->template = FALSE;

            header("content-type:application/csv;charset=UTF-8");
            header("Content-Disposition: attachment; filename=\"informe_ventas_unidades.csv\"");
            echo "referencia;codcliente;nombre;año;ene;feb;mar;abr;may;jun;jul;ago;sep;oct;nov;dic;total;%VAR\n";

            $cliente = new cliente();
            $stats = array();
            foreach ($data as $d) {
                $anyo = date('Y', strtotime($d['fecha']));
                $mes = date('n', strtotime($d['fecha']));
                if (!isset($stats[$d['referencia']][$d['codcliente']][$anyo])) {
                    $stats[$d['referencia']][$d['codcliente']][$anyo] = array(
                        1 => 0,
                        2 => 0,
                        3 => 0,
                        4 => 0,
                        5 => 0,
                        6 => 0,
                        7 => 0,
                        8 => 0,
                        9 => 0,
                        10 => 0,
                        11 => 0,
                        12 => 0,
                        13 => 0,
                        14 => 0
                    );
                }

                $stats[$d['referencia']][$d['codcliente']][$anyo][$mes] += floatval($d['total']);
                $stats[$d['referencia']][$d['codcliente']][$anyo][13] += floatval($d['total']);
            }

            foreach ($stats as $i => $value) {
                foreach ($value as $j => $value2) {
                    /// calculamos la variación
                    $anterior = 0;
                    foreach (array_reverse($value2, TRUE) as $k => $value3) {
                        if ($anterior > 0) {
                            $value2[$k][14] = ($value3[13] * 100 / $anterior) - 100;
                        }

                        $anterior = $value3[13];
                    }

                    $cli = $cliente->get($j);
                    foreach ($value2 as $k => $value3) {
                        if ($cli) {
                            echo '"' . $i . '";"' . $j . '";' . fs_fix_html($cli->nombre) . ';' . $k;
                        } else {
                            echo '"' . $i . '";"' . $j . '";-;' . $k;
                        }

                        foreach ($value3 as $value4) {
                            echo ';' . number_format($value4, FS_NF0, ',', '');
                        }

                        echo "\n";
                    }
                    echo ";;;;;;;;;;;;;;;\n";
                }
                echo ";;;;;;;;;;;;;;;\n";
            }
        } else {
            $this->new_error_msg('Sin resultados.');
        }
    }

    public function generar_archivo($archivo, $header, $rows, $format = 'csv')
    {
        $this->template = FALSE;

        if ($format == 'csv') {
            header("content-type:application/csv;charset=UTF-8");
            header("Content-Disposition: attachment; filename=\"" . $archivo . "_" . time() . ".csv\"");
            /// escribimos la cabecera
            foreach ($header as $key => $value) {
                echo $key . ';';
            }
            echo "\n";
            /// escribimos el resto de líneas
            foreach ($rows as $l) {
                $lin = implode(';', $l);
                echo $lin . "\n";
            }
        } else if ($format == 'xls') {
            header("Content-Disposition: attachment; filename=\"" . $archivo . "_" . time() . ".xlsx\"");
            header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
            header('Content-Transfer-Encoding: binary');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            $writer = new XLSXWriter();
            $writer->writeSheetHeader($archivo, $header);
            foreach ($rows as $l) {
                $writer->writeSheetRow($archivo, $l);
            }
            $writer->writeToStdOut();
        }
    }

    public function generar_pdf_list(){
        $objarticulo = new articulo();
        $objfamilia = new familia();
        $pdf = new cpc_fpdf();
        $pdf->AddPage();
        $pdf->SetMargins(0,0,0);
        $pdf->SetAutoPageBreak(0);
        $pdf->Image(__DIR__.'/../view/image/1-01.jpg',0,-1,211);
        $pdf->AddPage();
        $pdf->Image(__DIR__.'/../view/image/2-02.jpg',0,-1,211);
        $pdf->AddFont('Oganesson','','Oganesson.php');
        $y = 55;

        $linkfamilia = array();
        $linkarticulo = array();

        if ($this->familiaaux != '') {
            $familia[] = $objfamilia->get($this->familiaaux);
        }else{
            $familia = $objfamilia->madres();
        }

        $tarifa = false;
        if ($this->tarifaaux != '') {
            $tarifa = $this->objtarifa->get($this->tarifaaux);
        }

        $madre = ''; 
        $x = 10;

        $famiaux['list'] = array();
        $famiaux['principal'] = array();
        $famiaux['madre'] = array();
        $menu = array();
        $can = 0;
        $canarra = 0;
        $famican = 0;

        foreach ($familia as $i => $fa) {
            $hijas = $fa->hijas();
            $can2=0;
            if (count($hijas)>0) { 
                $canarra++;
                $canmadre = $canarra;
                $can++;
                $menu[$canarra]['descripcion'] = $fa->descripcion;
                $menu[$canarra]['inde'] = $can;
                $menu[$canarra]['xaux'] = 10;

                foreach ($hijas as $e => $huno) {
                    $can2++;
                    $hijauno = $huno->hijas();
                    if (count($hijauno)>0) {
                        $canarra++;
                        $menu[$canarra]['descripcion'] = $huno->descripcion;
                        $menu[$canarra]['inde'] = $can."-".$can2;
                        $menu[$canarra]['xaux'] = 20;
                        $can3 = 0;
                        $canmadre = $canarra;

                        foreach ($hijauno as $u => $hdos) {

                            $hijados = $hdos->hijas();
                            if (count($hijados)>0) {
                                $can3++;
                                $canarra++;
                                $menu[$canarra]['descripcion'] = $hdos->descripcion;
                                $menu[$canarra]['inde'] = $can."-".$can2."-".$can3;
                                $menu[$canarra]['xaux'] = 30;
                                $can4 = 0;

                                foreach ($hijados as $g => $htres) {
                                    
                                    if ($this->verificar_familia($htres->codfamilia)) {
                                        
                                        $famiaux['lick'][$famican] = $pdf->AddLink();
                                       
                                        $famiaux['list'][$famican] = $htres;

                                        $famiaux['principal'][$famican] =$fa->descripcion;
                                        $famiaux['madre'][$famican] = $hdos->descripcion;
                                       
                                        $can4++;
                                        
                                        $canarra++;
                                        $menu[$canarra]['descripcion'] = $huno->descripcion;
                                        $menu[$canarra]['inde'] = $can."-".$can2."-".$can3."-".$can4;
                                        $menu[$canarra]['xaux'] = 35;
                                        $menu[$canarra]['link'] = $famiaux['lick'][$famican];

                                        $famican++;
                                    }
                                }

                                if ($can4 ==0) {
                                    $can3--;
                                    unset($menu[$canarra]);
                                }

                            }else{
                                if ($this->verificar_familia($hdos->codfamilia)) {
                                    $can3++;
                                    $famiaux['lick'][$famican] = $pdf->AddLink();
                                    $famiaux['list'][$famican] = $hdos;

                                    $famiaux['principal'][$famican] = $fa->descripcion;
                                    $famiaux['madre'][$famican] = $huno->descripcion;
                                    
                                    $canarra++;
                                    $menu[$canarra]['descripcion'] = $hdos->descripcion;
                                    $menu[$canarra]['inde'] = $can."-".$can2."-".$can3;
                                    $menu[$canarra]['xaux'] = 30;
                                    $menu[$canarra]['link'] = $famiaux['lick'][$famican];

                                    $famican++;
                                }
                            }
                        }

                        if ($can3 ==0) {
                            $can2--;
                            unset($menu[$canmadre]);
                        }

                    }else{ 
                        if ($this->verificar_familia($huno->codfamilia)) {
                            $famiaux['lick'][$famican] = $pdf->AddLink();
                            $famiaux['list'][$famican] = $huno;
                            $famiaux['principal'][$famican] = $fa->descripcion;
                            $famiaux['madre'][$famican] = "";
                            
                            $canarra++;
                            $menu[$canarra]['descripcion'] = $huno->descripcion;
                            $menu[$canarra]['inde'] = $can."-".$can2;
                            $menu[$canarra]['xaux'] = 20;
                            $menu[$canarra]['link'] = $famiaux['lick'][$famican];

                            $famican++;
                            
                        }
                    }
                }
            }else{

                if ($this->verificar_familia($fa->codfamilia)) {
                    $can++;
                    $can2++;
                    $famiaux['lick'][$famican] = $pdf->AddLink();
                    $famiaux['list'][$famican] = $fa;
                    $famiaux['principal'][$famican] = $fa->descripcion;
                    $famiaux['madre'][$famican] = "";
                    
                    $canarra++;
                    $menu[$canarra]['descripcion'] = $fa->descripcion;
                    $menu[$canarra]['inde'] = $can;
                    $menu[$canarra]['xaux'] = 10;
                    $menu[$canarra]['link'] = $famiaux['lick'][$famican];

                    $famican++;
                    
                }
            }

            if ($can2 ==0) {
                $can--;
                unset($menu[$canmadre]);
            }
        }

        $y = 50;
        $pdf->SetFont('Oganesson','',20);
        foreach ($menu as $i => $m ) {
           $pdf->SetXY(20,$y);
           if (isset($m['link'])) {
                $pdf->SetFont('Oganesson','U',20);
                $pdf->cell($m['xaux'],5,$m['inde'],0,0,'L',false,$m['link']);
                $pdf->cell(200,5,$m['descripcion'],0,0,'L',false,$m['link']);
           }else{
                $pdf->SetFont('Oganesson','',20);
                $pdf->cell($m['xaux'],5,$m['inde'],0,0);
                $pdf->cell(200,5,$m['descripcion'],0,0);
           }
           
           $y+=8;
        }

        $pdf->SetTextColor(255,255,255);
        $nonfon = "4-04.jpg";

        if (count($famiaux['list'])>0) {
            
            foreach ($famiaux['list'] as $i => $fa) {
                
                    
                $pdf->AddPage();
                $pdf->Image(__DIR__.'/../view/image/'.$nonfon,0,-1,211);

                if ($nonfon == "3-03.jpg") {
                    $nonfon = "4-04.jpg";
                }else{
                    $nonfon = "3-03.jpg";
                }

                $aux = false;
                if ($famiaux['principal'][$i] != ""&&$famiaux['madre'][$i] !="") {
                    
                    $pdf->SetXY(3,3);
                    $pdf->SetTextColor(0,0,0);
                    $pdf->SetFont('Arial','B',20);
                    $pdf->cell(206,8,$famiaux['principal'][$i],0,0,"R");

                    $pdf->SetXY(3,12);
                    $pdf->SetTextColor(0,0,0);
                    $pdf->SetFont('Arial','B',20);
                    $pdf->cell(206,8,$famiaux['madre'][$i],0,0,"R");

                    $aux = true;

                }

                
                $pdf->SetFont('Arial','B',45);
                $descripcion = strtoupper($fa->descripcion);
                $descripcion = substr($descripcion,0,44);
                $pdf->SetTextColor(255,255,255);
                $pdf->SetLink($famiaux['lick'][$i]); 
                if ($aux) {
                   
                    $pdf->SetXY(3,20);           
                    $pdf->cell(206,15,$descripcion,0,0,"L");
                 # code...
                }else{
                    $pdf->SetXY(3,10);           
                    $pdf->cell(206,25,$descripcion,0,0,"L");
                }

                $y=50;
                $pdf->SetFillColor(249,87,0);
                $pdf->SetFont('Oganesson','',9);
                $sig = 0;
                $can = 0;
                $xaux = 61;
                $xaux2 = 10;
                $yaux = $y;

                $articulo = $fa->get_articulos(0,1000000000);

                if ($tarifa) {

                  $articulo =  $this->set_precios($tarifa,$articulo);

                }

                foreach ($articulo as $o => $art) {

                    if (!$art->bloqueado&&$art->stockfis) {
                        if ($sig > 1) {
                            $sig = 0;
                            $xaux = 61;
                            $xaux2 = 10;
                            $yaux += 61;
                        }

                        if ($can > 7) {
                            $pdf->AddPage();
                            $pdf->Image(__DIR__.'/../view/image/'.$nonfon,0,-1,211);
                            $pdf->SetXY(3,10);
                            $pdf->SetFont('Arial','B',45);
                            $pdf->SetTextColor(255,255,255);
                            $pdf->cell(206,25,$descripcion,0,0,"L");
                            $sig = 0;
                            $xaux = 61;
                            $xaux2 = 10;
                            $can = 0;
                            $yaux = 50;

                            if ($nonfon == "3-03.jpg") {
                                $nonfon = "4-04.jpg";
                            }else{
                                $nonfon = "3-03.jpg";
                            }
                        }

                        if (file_exists($art->imagen_url())) {
                            $pdf->Image($art->imagen_url(),$xaux2,$yaux,50,55);
                        }else{
                            $pdf->Image(__DIR__.'/../view/image/5-05.jpg',$xaux2,$yaux,50,55);
                        }
                        
                        $pdf->SetXY($xaux,$yaux);
                        $pdf->SetFont('Oganesson','',9);
                        $pdf->SetTextColor(255,255,255);
                        //$pdf->SetLink($linkarticulo[$art->referencia]);
                        $this->verificar_titulo($pdf,$yaux,$art->descripcion,20);

                        $pdf->SetTextColor(0,0,0);
                        $pdf->SetXY($xaux,$yaux+15);
                        $pdf->cell(40,4,"PRECIO :",0,0,"L");

                        $pdf->SetXY($xaux,$yaux+21);
                        $pdf->SetFont('Oganesson','',15);
                        $pdf->cell(40,4,$this->show_precio($art->pvp_iva()),0,0,"L");

                        $pdf->SetXY($xaux,$yaux+27);
                        $pdf->SetFont('Oganesson','',8);
                        $pdf->MultiCell(40,2,substr($art->observaciones,0,350),0,"J");

                        if ($sig == 0) {
                            $xaux = 159;
                            $xaux2 = 108;
                        }else{
                            $xaux = 61;
                            $xaux2 = 10;
                        }

                        $sig++;
                        $can++;
                    }
                }
            }
        }

        $pdf->Output();
    }

    public function verificar_titulo(&$pdf,$y,$titulo,$hasta){
        $resstr = "";
        if (strlen($titulo) > $hasta) {
            $resstr .= substr($titulo,0,$hasta);
            $resstr .= substr($titulo,$hasta,$hasta);
            $resstr = strtoupper($resstr);
            $pdf->MultiCell(40,5,$resstr,0,"C",true);
        }else{
            $resstr .= $titulo;
            $resstr = strtoupper($resstr);
            $pdf->Cell(40,10,$resstr,0,0,"C",true);
        }
    }

    public function verificar_familia($familia){
        $data = $this->db->select("SELECT * FROM articulos WHERE codfamilia = '".$familia."' AND bloqueado = false AND (stockfis > 0 OR nostock = true)");

        if (count($data)>0) {
            return true;
        }else{
            return false;
        }
    }

    public function set_precios($tarifa,$articulos)
    {
        foreach ($articulos as $i => $value) {

            $articulos[$i]->pvp = $articulos[$i]->pvp * (100 - $tarifa->x()) / 100 - $tarifa->y();

        }

        return $articulos;
    }
}
