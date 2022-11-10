<?php

use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\NoReturn;

class SSP
{
    /**
     * Returns the data output array for the DataTables rows
     *
     *  @param  array{db: string, dt: int, formatter: callable} $columns Column information array
     *  @param  array $data    Data from the SQL get
     *  @return array          Formatted data in a row based format
     */
    static function data_output (array $columns, array $data): array
    {
        $out = [];
        for ($i=0, $ien=count($data) ; $i<$ien ; $i++) {
            $row = [];
            for ($j=0, $jen=count($columns) ; $j<$jen ; $j++) {
                $column = $columns[$j];
              //  $ee = explode(".", $column["db"]);
              /*  if (count($ee) !== 1) {
                    var_dump($data);
                    exit();
                    $column['db'] = $columns[$j]["db"] = $ee[1];
                }*/
                if (isset($column['formatter'])) {
                    if (empty($column['db'])) {
                        $row[ $column['dt'] ] = $column['formatter']($data[$i]);
                    }
                    else {
                        $row[ $column['dt'] ] = $column['formatter']($data[$i][ $column['dt'] ], $data[$i]);
                    }
                }
                else {
                    if (!empty($column['db'])) {
                        $row[ $column['dt'] ] = $data[$i][ $columns[$j]['dt'] ];
                    }
                    else {
                        $row[ $column['dt'] ] = "";
                    }
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Paging
     *
     * Construct the LIMIT clause for server-side processing SQL query
     *
     *  @param  array $request Data sent to server by DataTables
     *  @return string SQL limit clause
     */
    static function limit (array $request): string
    {
        $limit = '';
        if (isset($request['start']) && $request['length'] != -1) {
            $limit = "LIMIT " . intval($request['length']) . " OFFSET ".intval($request['start']);
        }
        return $limit;
    }

    /**
     * Ordering
     *
     * Construct the ORDER BY clause for server-side processing SQL query
     *
     *  @param  array $request Data sent to server by DataTables
     *  @param  array $columns Column information array
     *  @return string SQL order by clause
     */
    static function order (array $request, array $columns): string
    {
        $order = '';
        if (isset($request['order']) && count($request['order'])) {
            $orderBy = [];
            $dtColumns = self::pluck($columns, 'dt');
            for ($i=0, $ien=count($request['order']) ; $i<$ien ; $i++) {
                $columnIdx = intval($request['order'][$i]['column']);
                $requestColumn = $request['columns'][$columnIdx];
                $columnIdx = array_search($requestColumn['data'], $dtColumns);
                $column = $columns[ $columnIdx ];
                if ($requestColumn['orderable'] == 'true') {
                    $dir = $request['order'][$i]['dir'] === 'asc' ?
                        'ASC' :
                        'DESC';

                    $orderBy[] = ''.$column['db'].' '.$dir;
                }
            }
            if (count($orderBy)) {
                $order = 'ORDER BY '.implode(', ', $orderBy);
            }
        }
        return $order;
    }

    /**
     * Searching / Filtering
     *
     * Construct the WHERE clause for server-side processing SQL query.
     *
     * NOTE this does not match the built-in DataTables filtering which does it
     * word by word on any field. It's possible to do here performance on large
     * databases would be very poor
     *
     *  @param  array $request Data sent to server by DataTables
     *  @param  array $columns Column information array
     *  @param  array $bindings Array of values for PDO bindings, used in the
     *    sql_exec() function
     *  @return string SQL where clause
     */
    static function filter (array $request, array $columns, array &$bindings): string
    {
        $globalSearch = [];
        $columnSearch = [];
        $dtColumns = self::pluck($columns, 'dt');
        if (isset($request['search']) && $request['search']['value'] != '') {
            $str = $request['search']['value'];
            $check_french_date_format = explode('/', $str);
            if (count($check_french_date_format))
            {
                $check_french_date_format = array_reverse($check_french_date_format);
                $str = $str . "|" . implode("-", $check_french_date_format);
            }
            for ($i=0, $ien=count($request['columns']) ; $i<$ien ; $i++) {
                $requestColumn = $request['columns'][$i];
                $columnIdx = array_search($requestColumn['data'], $dtColumns);
                $column = $columns[ $columnIdx ];

                if ($requestColumn['searchable'] == 'true') {
                    if (!empty($column['db'])) {

                        $possibilities = explode("|", $str);
                        $sql_or = "(false ";

                        foreach ($possibilities as $possibility)
                        {
                            $binding = self::bind($bindings, '%' . $possibility . '%');
                            $sql_or .= "OR (LOWER(CAST(".$column['db']." as varchar))  LIKE LOWER(".$binding . "))";
                        }

                        $sql_or .= ")";
                        $globalSearch[] = $sql_or;

                    }
                }
            }
        }
        if (isset($request['columns'])) {
            for ($i=0, $ien=count($request['columns']) ; $i<$ien ; $i++) {
                $requestColumn = $request['columns'][$i];
                $columnIdx = array_search($requestColumn['data'], $dtColumns);
                $column = $columns[ $columnIdx ];
                $str = $requestColumn['search']['value'];
                if ($requestColumn['searchable'] == 'true' &&
                    $str != '') {
                    if (!empty($column['db'])) {
                        $possibilities = explode("|", $str);
                        $sql_or = "(false ";

                        foreach ($possibilities as $possibility)
                        {
                            $binding = self::bind($bindings, '%' . $possibility . '%');
                            $sql_or .= "OR (LOWER(CAST(".$column['db']." as varchar))  LIKE LOWER(".$binding . "))";
                        }

                        $sql_or .= ")";
                        $columnSearch[] = $sql_or;
                    }
                }
            }
        }
        $where = '';
        if (count($globalSearch)) {
            $where = '('.implode(' OR ', $globalSearch).')';
        }
        if (count($columnSearch)) {
            $where = $where === '' ?
                implode(' AND ', $columnSearch) :
                $where .' AND '. implode(' AND ', $columnSearch);
        }
        if ($where !== '') {
            $where = 'WHERE '.$where;
        }
   //     var_dump($where);
   //     var_dump($bindings);
        return $where;
    }

    /**
     * Perform the SQL queries needed for an server-side processing requested,
     * utilising the helper functions of this class, limit(), order() and
     * filter() among others. The returned array is ready to be encoded as JSON
     * in response to an SSP request, or can be modified if needed before
     * sending back to the client.
     *
     *  @param  array $request Data sent to server by DataTables
     *  @param  PDO $db PDO connection resource
     *  @param  string $table SQL table to query
     *  @param  string $primaryKey Primary key of the table
     *  @param  array $columns Column information array
     *  @return array          Server-side processing response array
     */
    #[ArrayShape(["draw" => "int", "recordsTotal" => "int", "recordsFiltered" => "int", "data" => "array"])]
    static function simple (array $request, PDO $db, string $table, string $primaryKey, array $columns): array
    {
        $bindings = [];
        $limit = self::limit($request);
        $order = self::order($request, $columns);
        $where = self::filter($request, $columns, $bindings);
        $data = self::sql_exec($db, $bindings,
            "SELECT ".implode(", ", self::pluck($columns, 'db'))."
			 FROM $table
			 $where
			 $order
			 $limit"
       );
        $resFilterLength = self::sql_exec($db, $bindings,
            "SELECT COUNT($primaryKey)
			 FROM   $table
			 $where"
       );
        $recordsFiltered = $resFilterLength[0][0];
        $resTotalLength = self::sql_exec($db, [],
            "SELECT COUNT($primaryKey)
			 FROM   $table"
       );
        $recordsTotal = $resTotalLength[0][0];
        return [
            "draw"            => isset ($request['draw']) ?
                intval($request['draw']) :
                0,
            "recordsTotal"    => intval($recordsTotal),
            "recordsFiltered" => intval($recordsFiltered),
            "data"            => self::data_output($columns, $data)
       ];
    }

    /**
     * The difference between this method and the simple one, is that you can
     * apply additional where conditions to the SQL queries. These can be in
     * one of two forms:
     *
     * * 'Result condition' - This is applied to the result set, but not the
     *   overall paging information query - i.e. it will not effect the number
     *   of records that a user sees they can have access to. This should be
     *   used when you want apply a filtering condition that the user has sent.
     * * 'All condition' - This is applied to all queries that are made and
     *   reduces the number of records that the user can access. This should be
     *   used in conditions where you don't want the user to ever have access to
     *   particular records (for example, restricting by a login id).
     *
     *  @param  array $request Data sent to server by DataTables
     *  @param  PDO $db PDO connection resource
     *  @param  string $table SQL table to query
     *  @param  string $primaryKey Primary key of the table
     *  @param  array $columns Column information array
     *  @param  string|null $whereResult WHERE condition to apply to the result set
     *  @param  string|null $whereAll WHERE condition to apply to all queries
     *  @return array         Server-side processing response array
     */
    #[ArrayShape(["draw" => "int", "recordsTotal" => "int", "recordsFiltered" => "int", "data" => "array"])] static function
    complex (array $request, PDO $db, string $table, string $primaryKey, array $columns, string $whereResult=null, string $whereAll=null): array
    {
        $bindings = [];
        $whereAllSql = '';
        $limit = self::limit($request);
        $order = self::order($request, $columns);
        $where = self::filter($request, $columns, $bindings);
    //    var_dump($where);
        $whereResult = self::_flatten($whereResult);
        $whereAll = self::_flatten($whereAll);
        if ($whereResult) {
            $where = $where ?
                $where .' AND '.$whereResult :
                'WHERE '.$whereResult;
        }
        if ($whereAll) {
            $where = $where ?
                $where .' AND '.$whereAll :
                'WHERE '.$whereAll;

            $whereAllSql = 'WHERE '.$whereAll;
        }
        $data = self::sql_exec($db, $bindings,
            "SELECT ".implode(", ", self::pluck($columns, 'db'))."
			 FROM $table
			 $where
			 $order
			 $limit"
       );
        $resFilterLength = self::sql_exec($db, $bindings,
            "SELECT COUNT($primaryKey)
			 FROM   $table
			 $where"
       );
        $recordsFiltered = $resFilterLength[0][0];
        $resTotalLength = self::sql_exec($db, [],
            "SELECT COUNT($primaryKey)
			 FROM   $table ".
            $whereAllSql
       );
        $recordsTotal = $resTotalLength[0][0];
        return [
            "draw"            => isset ($request['draw']) ? intval($request['draw']) : 0,
            "recordsTotal"    => intval($recordsTotal),
            "recordsFiltered" => intval($recordsFiltered),
            "data"            => self::data_output($columns, $data)
       ];
    }

    /**
     * Execute an SQL query on the database
     *
     * @param  PDO $db  Database handler
     * @param  array    $bindings Array of PDO binding values from bind() to be
     *   used for safely escaping strings. Note that this can be given as the
     *   SQL query string if no bindings are required.
     * @param  string|null   $sql SQL query to execute.
     * @return array         Result from the query (all rows)
     */
    static function sql_exec (PDO $db, array $bindings, string $sql=null): array
    {
        if ($sql === null) {
            $sql = $bindings;
        }
        $stmt = $db->prepare($sql);
        for ($i=0, $ien=count($bindings) ; $i<$ien ; $i++) {
            $binding = $bindings[$i];
            $stmt->bindValue($binding['key'], $binding['val'], $binding['type']);
        }
        try {
            $stmt->execute();
        }
        catch (PDOException $e) {
            self::fatal("An SQL error occurred: ".$e->getMessage());
        }
        return $stmt->fetchAll(PDO::FETCH_BOTH);
    }

    /**
     * Throw a fatal error.
     *
     * This writes out an error message in a JSON string which DataTables will
     * see and show to the user in the browser.
     *
     * @param  string $msg Message to send to the client
     */
    #[NoReturn]
    private static function fatal (string $msg): void
    {
        echo json_encode([
            "error" => $msg
        ]);

        exit(0);
    }

    /**
     * Create a PDO binding key which can be used for escaping variables safely
     * when executing a query with sql_exec()
     *
     * @param  array &$a    Array of bindings
     * @param  mixed      $val  Value to bind
     * @return string       Bound key to be used in the SQL where this parameter
     *   would be used.
     */
    private static function bind (array &$a, mixed $val): string
    {
        $key = ':binding_'.count($a);
        $a[] = [
            'key' => $key,
            'val' => $val,
            'type' => PDO::PARAM_STR
        ];
        return $key;
    }

    /**
     * Pull a particular property from each assoc. array in a numeric array,
     * returning and array of the property values from each item.
     *
     *  @param  array  $a    Array to get data from
     *  @param  string $prop Property to read
     *  @return array        Array of property values
     */
    static function pluck (array $a, string $prop): array
    {
        $out = [];
        for ($i=0, $len=count($a) ; $i<$len ; $i++) {
            if (empty($a[$i][$prop])) {
                continue;
            }
            //removing the $out array index confuses the filter method in doing proper binding,
            //adding it ensures that the array data are mapped correctly
            $out[$i] = $a[$i][$prop];
        }
        return $out;
    }

    /**
     * Return a string from an array or a string
     *
     * @param  array|string $a Array to join
     * @param  string $join Glue for the concatenation
     * @return string Joined string
     */
    static function _flatten (mixed $a, string $join = ' AND '): string
    {
        if (! $a) {
            return '';
        }
        else if (is_array($a)) {
            return implode($join, $a);
        }
        return $a;
    }
}
