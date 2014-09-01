<?php

include('simple_html_dom.php');

class StealthOptions {

   // Update sqlite database with symbols from weeklyoptions.csv
   var $symbols_to_search;
   var $unindexed_symbols;
   var $unfiltered_symbols;
   var $arguments;
   var $optionsdate;
   var $room; // space for trade to go against me
   var $options_series_dates;
   var $current_price;
   var $acceptable_put_price;
   var $acceptable_links_list;
   var $database;
   var $html;
   var $debug;

   public function __construct($argv)
   {
      // set debug mode?
      ini_set('display_errors',1);
      ini_set('display_startup_errors',1);
      error_reporting(-1);
      
      // Initiate variables
      $this->debug = False;
      $this->arguments = $argv;
      $this->symbols_to_search = array();
      $this->unindexed_symbols = array();
      $this->unfiltered_symbols = array();
      $this->acceptable_links_list = array();
      $this->room = 0.95;
      $this->optionsdate = '2014-09';
      $this->options_series_dates = array("140905","140912","140920","140926");
      $this->database = $this->_get_db_instance();

      // If --reset is set, rebuild the symbols table
      if (isset($this->arguments[1]) && $this->arguments[1] == '--reset') {
         $this->populate_symbols_to_search_array();
         $this->populate_db_with_symbols_to_search();
      } else {
         if ($this->debug) {
            echo "Continuing updates on symbols\n";
         }
         
         $this->_build_raw_trades_table();
         $this->_update_unindexed_symbols();
         foreach ($this->unindexed_symbols as $symbol) {
            $this->_get_raw_trades_for_symbol($symbol);
            $this->_update_unindexed_symbols();
         }
         // If we got this far we have updated all available trades with no filter
         echo "\n";
         echo "All Trades Indexed.\n---\n";
         /*
         echo "All trades indexed successfully. Would you like to update ratings? (y/n): ";
         $stdin = fopen("php://stdin", 'r');
         $users_choice = fgetc($stdin);
         if ($users_choice != 'y' && $users_choice != 'Y') {
            die("Bye\n");
         } else {
            echo "\n\n";
         }
         */

         // Now we populate the table with the ratings so we can make some awesome reports in the future
         // filtering results by ratings

         $this->_update_unfiltered_symbols();
         foreach ($this->unfiltered_symbols as $symbol) {
            $this->_update_rating_for_symbol($symbol);
            $this->_update_unfiltered_symbols();
         }

         // If we got this far we updated all ratings too
         echo "All Ratings Updated.\n---\n\n";

         $users_choice = -1;
         while ($users_choice != '0') {
            
            // Show available reports while the user doesn't choose to leave
            echo "What would you like to do now?\n";
            echo "(1) See trades with (BUY) BHS\n";
            echo "(2) See trades with A+ ratings\n";
            echo "(3) See trades with X ratings\n";
            echo "(4) ** Reset all data **\n";
			echo "(5) Trades with high Capital Efficiency (>=4)\n";
            echo "(0) Exit\n";
            echo "Enter choice: ";
            $stdin = fopen("php://stdin", 'r');
            $users_choice = fgetc($stdin);

            switch ($users_choice) {
               case '1':
                  $this->show_report('buy');
                  break;
               case '2':
                  $this->show_report('a+');
                  break;
               case '3':
                  echo "\nAvailable ratings: A+ A A- B+ B B- C+ C C- D+ D D- E+ E E- F+ F F-\n";
                  echo "Please input the rating to search for: ";
                  $stdin = fopen("php://stdin", 'r');
                  $rating = fgets($stdin);
                  $this->show_report($rating);
                  break;
               case '4':
                  $this->reset_data();
				case '5':
					$this->show_report('high_ce');
               case '0':
                  echo "\n\nBye!\n\n";
                  break;
            }

         }
      }
      
   }

   public function reset_data()
   {
      echo "\n\nResetting data for a new Options Search.\n";
      $query = "UPDATE SymbolsUniverse SET Indexed = 0, Filtered = 0;";
      $this->database->query($query);
      echo "Symbols reset to not-indexed and unfiltered.\n";
	  $query = "DELETE FROM RawTrades;";
	  $this->database->exec($query);
	  echo "Raw Trades reset.\n";
      echo "---\n";
   }

   public function show_report($filter)
   {
      switch ($filter) {
         case 'buy':
            $query = "SELECT * FROM RawTrades WHERE BHS = '(Buy)' ORDER BY Rating";
            break;
         case 'a+':
            $query = "SELECT * FROM RawTrades WHERE Rating = 'A+' ORDER BY Rating";
            break;
		case 'high_ce':
			$query = "SELECT * FROM RawTrades WHERE CE >= 4 ORDER BY Rating,CE DESC";
			break;
         default:
            $filter = rtrim($filter, "\n");
            $query = "SELECT * FROM RawTrades WHERE Rating = '$filter' ORDER BY Rating";
            break;
      }
      $results = $this->database->query($query);
      $symbols = array();

      echo "\n\n-----\n\nReports results for $filter:\n\n";

      while ($row = $results->fetchArray()) {
         $symbol = $row['Symbol'];
         $date = $row['Date'];
         $current_price = $row['CurrentPrice'];
         $ce = $row['CE'];
         $strike = $row['Strike'];
         $bid = $row['Bid'];
         $ask = $row['Ask'];
         $mid = ($bid+$ask)/2;
         $logged_on = $row['LoggedOn'];
         $rating = $row['Rating'];
         $bhs = $row['BHS'];
         echo "$symbol $$current_price:  $date/$strike  $$bid/$$ask/$$mid  Rating: $rating  BHS: $bhs  CE: $ce\n";
         array_push($symbols, "$symbol($rating)");
      }
      echo "\n";
      // Print symbols
      $symbols = array_unique($symbols);
      $num_symbols = count($symbols);
      echo "$num_symbols Tickers in this report: ";
      foreach ($symbols as $symbol) {
         echo "$symbol ";
      }
      echo "\n\n-----\n\n";
   }

   public function _update_rating_for_symbol($symbol)
   {
      echo "Updating ratings for $symbol...\n";

      // Check if we have trades available for this symbol
      $count = $this->database->querySingle("SELECT COUNT(*) as count FROM RawTrades WHERE Symbol = '$symbol'");

      if ($count == 0) {
         echo "No trades found for $symbol\n---\n";
         // Set Filtered to true
         $this->_set_symbol_as_indexed($symbol,True);
      } else {
         echo "Found $count available trades\n";
         // We have available trades for this symbol
         $link = "http://www.thestreet.com/quote/$symbol.html";
         // Get HTML
         $this->html = new simple_html_dom();
         #echo "Link: $link\n";
         $this->html->load_file($link);
         $rating = $this->html->find('#currentRating',0);
         if (!$rating) {
            // Rating was not found, keep N/A value
            $rating_score = "N/A";
            $bhs = "N/A";
         } else {
            list($rating_score,$bhs) = explode(" ", $rating->innertext);
            $bhs = trim($bhs);
         }
         echo "Current rating for $symbol: $rating_score\nBHS for $symbol: $bhs\n";
         $updated_successfully = $this->_save_new_rating_for_symbol($symbol,$rating_score,$bhs);
         if ($updated_successfully) {
            // Set Filtered to true
            $this->_set_symbol_as_indexed($symbol,True);
         } else {
            die("Exiting...\n");
         }
      }

   }

   public function _save_new_rating_for_symbol($symbol,$rating,$bhs)
   {
      $query = "UPDATE RawTrades SET Rating = '$rating', BHS = '$bhs' WHERE Symbol = '$symbol'";
      if(!$this->database->exec($query))
      {
         echo "Could not update ratings for $symbol!\n";
         return False;
      } else {
         echo "Ratings for $symbol updated successfully.\n---\n";
         return True;
      }
   }

   public function _update_unfiltered_symbols()
   {
      $this->unfiltered_symbols = array();
      $results = $this->database->query('SELECT Symbol,Indexed,Filtered FROM SymbolsUniverse WHERE Filtered = 0');
      while ($row = $results->fetchArray()) {
         array_push($this->unfiltered_symbols, $row['Symbol']);
      }
   }

   public function _build_raw_trades_table()
   {
      // Build RawTrades table if not exists
      // RawTrades table
      /*
         RawTrades
         TEXT Symbol | TEXT Date | REAL CurrentPrice | REAL CE | REAL Strike | REAL Bid | REAL Ask | INTEGER LoggedOn (UNIX Time)
      */
      // Create RawTrades table
      $query = 'CREATE TABLE IF NOT EXISTS RawTrades' .
         '(Symbol TEXT, Date TEXT, CurrentPrice REAL, CE REAL, Strike REAL, Bid REAL, Ask REAL, LoggedOn INTEGER, Rating TEXT, BHS TEXT)';

      if(!$this->database->exec($query))
      {
         die("Could not create table RawTrades :(\nExiting...\n");
      } else {
         #echo "Table RawTrades created successfully\n";
      }
   }

   public function _get_db_instance()
   {
      //create or open the database
      $db = new SQLite3('stealthoptions.sqlite');
      if(!$db) {
         echo "Boom!\n";
         die("Could not connect to database :(\nExiting...\n");
      } else {
         if ($this->debug) {
            echo "Database opened successfully\n";
         }
         return $db;
      }
   }

   public function populate_symbols_to_search_array()
   {
      // Here we use an incoming parameter to use a specific set of symbols as input for our search
      if (isset($this->arguments[2])) {
         $filename = $this->arguments[2];
      } else {
         // If no argument, fall back to default (search weeklyoptions.csv file)
         $filename = 'weeklyoptions.csv';
      }
      switch ($filename) {
         case 'weeklyoptions.csv':
            $handle = fopen("weeklyoptions.csv","r");
            while (($data = fgetcsv($handle)) !== FALSE) {
               array_push($this->symbols_to_search,trim($data[0]));
            }
            fclose ($handle);
            // Remove first three items (headers and crap)
            for ($i=0; $i < 4; $i++) { 
               array_shift($this->symbols_to_search);
            }
            $this->symbols_to_search = array_unique($this->symbols_to_search);
         break;
      }
   }

   public function populate_db_with_symbols_to_search()
   {
      /*
      TEXT SYMBOL | INTEGER INDEXED(0,1)
      Here we save the updated symbol list in the symbols_universe.sqlite file
      When we want a differente search, we simply delete the db file and start over
      */
      /*
      $db->exec('CREATE TABLE foo (bar STRING)');
      $db->exec("INSERT INTO foo (bar) VALUES ('This is a test')");
      $result = $db->query('SELECT bar FROM foo');
      var_dump($result->fetchArray());
      */


      $this->database = $this->_get_db_instance();

      // Drop and recreate table
      $query = 'DROP TABLE IF EXISTS SymbolsUniverse';
      if(!$this->database->exec($query))
      {
        die("Could not drop table SymbolsUniverse :(\nExiting...\n");
      } else {
         echo "Table SymbolsUniverse dropped successfully\n";
      }

      // Create symbols_universe table
      $query = 'CREATE TABLE IF NOT EXISTS SymbolsUniverse' .
         '(Symbol TEXT, Indexed INTEGER, Filtered INTEGER)';

      if(!$this->database->exec($query))
      {
        die("Could not create table SymbolsUniverse :(\nExiting...\n");
      } else {
         echo "Table SymbolsUniverse recreated successfully\n";
      }

      // Build query to insert all symbols in database table SymbolsUniverse
      $query = "INSERT INTO 'SymbolsUniverse' ('Symbol','Indexed','Filtered')";
      $multiple_inserts = '';
      foreach ($this->symbols_to_search as $symbol) {
         // Build query
         $multiple_inserts .= "UNION SELECT '$symbol',". 0 .",". 0 . " ";
      }
      // Remove first UNION
      $multiple_inserts = substr($multiple_inserts, 6);

      # echo "Query: $query.$multiple_inserts\n";
      // Build full query
      $query = $query.$multiple_inserts;
      // Run the multiple insert query
      if(!$this->database->exec($query))
      {
        die("Could not insert symbols in SymbolsUniverse table :(\nExiting...\n");
      } else {
         echo "Symbols inserted successfully in SymbolsUniverse database.\n";
      }
   }

   public function _set_price_from_html($ticker)
   {
      //var_dump($full_html);
      $lowercase_ticker = strtolower($ticker);
      $price_node = $this->html->find("#yfs_l84_$lowercase_ticker", 0);
      if (!$price_node) {
         return False;   // Return price_found
      } else {
         $this->current_price = $price_node->innertext;
         return True;
      }
   }

   public function _is_fraction($number)
   {
      (float)$whole = floor($number);
      $fraction = $number - $whole;
      if ($fraction != 0) {
         $return = true;
      } else {
         $return = false;
      }
      return $return;
   }

   public function _set_acceptable_put_price($stock_price)
   {
      // We need a put option with room to move
      // Here we get a number ending in 0 or 0.5
      $room = $this->room;
      $aprice = round($stock_price * $room,1);
      (float)$whole = floor($aprice);
      $fraction = $aprice - $whole;
      if ($fraction >= 0.5) {
         $fraction = 0.5;
      } else {
         $fraction = 0;
      }
      #echo "Acceptable put price: ".($whole+$fraction)."\n";
      $this->acceptable_put_price = $whole+$fraction;
   }

   public function _set_acceptable_links_list($acceptable_put_price, $ticker, $full_html)
   {
      //  /q?s=BIDU140905P00187500
      // 187.5  AMD140829P00187500
      // 4.5    AMD140829P00004500
      $dates = $this->options_series_dates;
      $links = [];
      $normalized_price = str_pad($acceptable_put_price*1000,8,"0",STR_PAD_LEFT);
      foreach ($dates as $date) {
         $link_text = $ticker.$date."P".$normalized_price;
            $link_href = "/q?s=$link_text";
            array_push($links, $link_href);
      }
      if ($this->_is_fraction($acceptable_put_price)) {
         $normalized_price = str_pad(($acceptable_put_price-0.5)*1000,8,"0",STR_PAD_LEFT);
         foreach ($dates as $date) {
            $link_text = $ticker.$date."P".$normalized_price;
            $link_href = "/q?s=$link_text";
            array_push($links, $link_href);
         }
      }
      $link_str = "";
      foreach ($links as $link) {
         $link_str .= "a[href='$link'],";
      }
      $link_str = rtrim($link_str, ",");
      //echo "Link STR: $link_str";
      $links= $full_html->find($link_str);
      $this->acceptable_links_list = $links;
   }

   public function _get_results($ticker, $links)
   {
      $result = "";

      if (count($links) == 0) {
         echo "Nenhuma opção encontrada para $ticker\n";
         //file_put_contents("optionsnotfound.dat", "$ticker\n", FILE_APPEND);
      } else {

         foreach ($links as $link) {
            $price = $link->parent()->prev_sibling()->plaintext;
            $bid_td = $link->parent()->next_sibling()->next_sibling()->next_sibling();
            $ask_td = $bid_td->next_sibling();
            $bid = $bid_td->plaintext;
            $ask = $ask_td->plaintext;
            $mid = ($bid + $ask) / 2;
            $ce = round(($mid * 100 / $price),2);
            $date = substr($link->plaintext, strlen($ticker),6); // BIDU140912P00207500

            if ($ce < 1) {
               echo "Discarded\n";
               continue;
            } else {
               // Save to db table RawTrades
               // (Symbol TEXT, Date TEXT, CurrentPrice REAL, CE REAL, Strike REAL, Bid REAL, Ask REAL, LoggedOn INTEGER)
               $query = "INSERT INTO RawTrades (Symbol,Date,CurrentPrice,CE,Strike,Bid,Ask,LoggedOn,Rating,BHS) VALUES ";
               $logged_on = time();
               $query .= "('$ticker','$date','$this->current_price','$ce','$price','$bid','$ask','$logged_on','N/A','N/A');";
               #echo "Query: $query\n";
               if(!$this->database->exec($query))
               {
                 die("Could not insert trade for $ticker in RawTrades table :(\nExiting...\n");
               } else {
                  echo "Trade for $ticker with CE of $ce found.\n";
               }
            }

         }
      }
   }

   public function _set_symbol_as_indexed($symbol, $filtered = False)
   {
      if (!$filtered) {
         $query = "UPDATE SymbolsUniverse SET Indexed = 1 WHERE Symbol = '$symbol'";
      } else {
         $query = "UPDATE SymbolsUniverse SET Filtered = 1 WHERE Symbol = '$symbol'";
      }

      if(!$this->database->exec($query))
      {
        die("Could not update $symbol's status in SymbolsUniverse table :(\nExiting...\n");
      } else {
         if ($this->debug) {
            //echo "$symbol updated successfully in SymbolsUniverse table.\n";
         }
      }
   }

   public function _get_raw_trades_for_symbol($symbol)
   {
      // Get options for this symbol
      echo "Analyzing $symbol\n";
      // Get options link
      $link = "http://finance.yahoo.com/q/op?s=$symbol&m=$this->optionsdate";
      // Get HTML
      $this->html = new simple_html_dom();
      #echo "Link: $link\n";
      $this->html->load_file($link);
      #echo "Got HTML!\n";
      $price_found = $this->_set_price_from_html($symbol);
      if ($price_found) {
         $this->_set_acceptable_put_price($this->current_price);
         $this->_set_acceptable_links_list($this->acceptable_put_price,$symbol,$this->html);
         // get results
         $this->_get_results($symbol,$this->acceptable_links_list);
         
      } else {
         echo "Price not found for $symbol. Moving on...\n";
      }
      // Set as Indexed in SymbolsUniverse table
      $this->_set_symbol_as_indexed($symbol);
      // update $this->unindexed_symbols
      $this->_update_unindexed_symbols();
      // Clear DOM (no more memory exhaustion glitches)
      $this->html->clear();
   }

   public function _update_unindexed_symbols()
   {
      $this->unindexed_symbols = array();
      $results = $this->database->query('SELECT Symbol,Indexed,Filtered FROM SymbolsUniverse WHERE Indexed = 0');
      while ($row = $results->fetchArray()) {
         array_push($this->unindexed_symbols, $row['Symbol']);
      }
   }

}

$n = new StealthOptions($argv);