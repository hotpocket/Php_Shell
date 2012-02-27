<?php
class Bash {

    const STDOUT_EOF = "--stdout_end_of_stream--";
    const STDERR_EOF = "--stderr_end_of_stream--";

    private $_shellProc;
    
    private $_stderr;
    private $_stdout;
    private $_stdin;

    private static $_runAndDeleteSh;
    
    public function __construct() {
        if(empty(self::$_runAndDeleteSh)){
            // bash source inside the magic bash <(...) construct which returns a shell script file handle.
            // That FH is the shell script that executes, wrapping the execution of the ->run($cmd...)
            // providing us with consistent EOF tokens for each process executed from within the 1 resident
            // bash process
            self::$_runAndDeleteSh = '<(echo "retStat=1;endstreams(){ '
                .'echo \"'.self::STDOUT_EOF.'\$retStat\";echo \"'.self::STDERR_EOF.'\" >&2;exit; };'
		.'trap endstreams 0 2 3 6 15;bash \$1;retStat=\$?;rm \$1;")';
            //echo self::$_runAndDeleteSh;
        }
    }

    
    /**
     * Executes $cmd via a bash shell and seperates STDERR and STDOUT in seperate streams that can be attached to via callbacks
     * @param string $cmd The shell command to execute
     * @param bool $background If true will throw into background and return immediately.
     *        NOTE: If backgrounded then no useful data can be returned because the process will not have time to generate any.
     * @param string $tmpShName the name of the temp shell script created and run which contains $cmd.
     *               Useful to specify if tracking of the executed command is desired with some binary like ps or lsof
     * @param function $stdoutCallback A function/closure object taking 1 string arg.
     *                 Called with increments of output from stdout as it is processed from shell $cmd
     *                 If used keep in mind that these partial output snippits WILL INCLUDE the program inserted end of stream tokens
     * @param function $stderrCallback An function/closure object taking 1 string arg.
     *                 Called with increments of output from stderr as it is processed from shell $cmd
     *                 If used keep in mind that these partial output snippits WILL INCLUDE the program inserted end of stream tokens
     * @return array An array with four keys,
     * - ['command'] The command that was actually executed.
     * - ['status']  The integer exit status, any non 0 status means failure.
     * - ['output']  An array of lines of text returned from this commands STDOUT
     * - ['error']   An array of lines of text returned from this commands STDERR
     */
    public function run($cmd, $background=false, $stdoutCallback = NULL, $stderrCallback = NULL){
        if($stdoutCallback !== NULL && !is_callable($stdoutCallback)){
           throw new Exception("Invalid stdout callback");
        }
        if($stderrCallback !== NULL && !is_callable($stderrCallback)){
            throw new Exception("Invalid stderr callback");
        }
        if(empty($this->_stderr) || empty($this->_stdin) || empty($this->_stdout)){
            $descSpec = array(
                array('pipe','r'),  // stdin
                array('pipe','w'),  // stdout
                array('pipe','w')   // stderr
            );

            // export $_SERVER into this shell process
            $envInit = array();
            foreach($_SERVER as $key=>$value){
                if (is_string($value)){
                    $envInit['PARENT_SERVER_'.$key] = $value;
                }
            }
            // add more to $envInit if needed here
            
            $this->_shellProc = proc_open('bash', $descSpec, $pipes,'/tmp',$envInit);
            if(!is_resource($this->_shellProc)){ throw new Exception("Could not init shell process"); }
            $this->_stdin  = &$pipes[0];
            $this->_stdout = &$pipes[1];
            $this->_stderr = &$pipes[2];

            stream_set_blocking($this->_stdout,false);
            stream_set_blocking($this->_stderr,false);
        }

        $tmpShName = microtime(true) .'-'. getmypid() .'-'. rand(0,42).".bash";
        $tmpSh = "/tmp/$tmpShName";
        $fp = fopen($tmpSh,'w+');
        fwrite($fp,$cmd);
        fclose($fp);

        $return = array(
            'output'    => array(),
            'error'     => array(),
            'command'   => $cmd,
            'status'    => 0
        );

        $bashCmd = "bash ". self::$_runAndDeleteSh ." $tmpSh";
        // for backgrounds processes stdout & stderr streams must be muted or else the process will not background
        if($background){ 
            $bashCmd .= ' 2> /dev/null > /dev/null &';
        }
        
        #echo "Executing this cmd via resident shell process\n";
        #var_export(array('sh_contents'=>$cmd,'sh_cmd'=>$bashCmd));

        fwrite($this->_stdin, "$bashCmd\n");
        fflush($this->_stdin);

        // buffers contents of streams to date and eof flags for both
        $stderrBuff = '';
        $stdoutBuff = '';
        $eofStderr = false;
        $eofStdout = false;
        // position and segment of buffers such that we can chunk data to callbacks by line.
        $stderrPos = 0;
        $stdoutPos = 0;
        $stderrSegment = '';
        $stdoutSegment = '';
        // so stream_select dosn't throw "not a var" error
        $write = $except = null;
        if(!$background){ // a backgrounded process will not have any stdout or stderr
            while(true){
                // poll stderr
                $stderr  = array($this->_stderr);
                $rv = stream_select($stderr,$write,$except,0,1000); // read stderr for .001s
                $strIn = stream_get_line($this->_stderr,4096);
                // just interacting with a shell, all IO should be ascii 
                // or binary is expected & parsed by the cmd author in the cmd
                $strIn = str_replace(array("\r\n","\r"),"\n",$strIn);
                if(!empty($strIn)) {
//                    echo "stderr read:\n$strIn\n";
                    $stderrBuff .= $strIn;
                    if($stderrCallback !== NULL) {
                        $stderrSegment .= $strIn;
                        while(($pos = strpos($stderrSegment,"\n")) !== false){
                            $line =  substr($stderrSegment,0,$pos);
//                            echo "stdout calling callback with line:\n$line\n";
                            $stderrCallback($line);
                            $stderrSegment = substr_replace($stderrSegment,'',0,$pos+1); // remove line from segment
                        }
                    }
                    if(($pos = strpos($stderrBuff,self::STDERR_EOF)) !== false) {
                        $stderrBuff = substr_replace($stderrBuff,'',$pos); // drop end_of_stream token from buffer
                        $eofStderr = true;
                    }
                }

                // poll stdout
                $stdout  = array($this->_stdout);
                $rv = stream_select($stdout,$write,$except,0,1000); // read stdout for .001s
                $strIn = stream_get_line($this->_stdout,4096);
                $strIn = str_replace(array("\r\n","\r"),"\n",$strIn);
                if(!empty($strIn)) {
//                    echo "stdout read:\n$strIn";
                    $stdoutBuff .= $strIn;
                    if($stdoutCallback !== NULL) {
                       $stdoutSegment .= $strIn;
                        while(($pos = strpos($stdoutSegment,"\n")) !== false){
                            $line =  substr($stdoutSegment,0,$pos);
//                            echo "stdout calling callback with line:\n$line";
                            $stdoutCallback($line);
                            $stdoutSegment = substr_replace($stdoutSegment,'',0,$pos+1); // remove line from segment
                        }
                    }
                    if(($pos = strpos($stdoutBuff,self::STDOUT_EOF)) !== false) {
                        $return['status'] = intval(trim(substr($stdoutBuff,$pos+strlen(self::STDOUT_EOF))));
                        $stdoutBuff = substr_replace($stdoutBuff,'',$pos); // drop end_of_stream token from buffer
                        $eofStdout = true;
                    }
                }
                if($eofStderr && $eofStdout) break;
            }
            $stderrBuff = trim($stderrBuff,"\r\n");
            $stdoutBuff = trim($stdoutBuff,"\r\n");
            $return['error']  = $stderrBuff === "" ? array() : explode("\n",$stderrBuff);
            $return['output'] = $stdoutBuff === "" ? array() : explode("\n",$stdoutBuff);
        }

        #echo "ShellCmd#>\n";
        #var_export($return);

        return $return;
    }
}

