<?php
class Bash {
    const STDOUT_EOF = "--stdout_end_of_stream--";
    const STDERR_EOF = "--stderr_end_of_stream--";

    /**
     * Initially created to init the parent bash env with eval `ssh-agent`
     * because any attempt to do so via a $cmd fails because run($cmd) executes in a sub shell
     * and once it's done the agent becomes unavailable to future calls to run()
     * In addition to the prior method not working, it also was leaving behind a <defunct> process ... probably not good ...
     * @var string The bash string you want executed in the root bash process prior to the next call to the run($cmd) function
     */
    private $_rootProcExec = array();

    private $_shellProc;

    private $_stderr;
    private $_stdout;
    private $_stdin;

    private static $_runAndDeleteSh;
    /**
     * Checked when run() is called to see if environment info is passed to the child.
     * Can matter when that environment info is calculated and impacts other processes in this process (e.g. session id)
     * @var bool
     */
    protected $_exportEnv = true;

    protected $_hasKeyring = false;

    /**
     * Switched to false on first run, used to initalize things prior to the first run of a command.
     * Initially created so we can export the path prior to running any command so we can find the binaries we need.
     * @var $_firstRun
     */
    protected $_firstRun = true;

    public function __construct() {
        if(empty(self::$_runAndDeleteSh)){
            // bash source inside the magic bash <(...) construct which returns a shell script file handle.
            // That FH is the shell script that executes, wrapping the execution of the ->run($cmd...)
            // providing us with consistent EOF tokens for each process executed from within the 1 resident
            // bash process
            self::$_runAndDeleteSh = '<(echo "retStat=1;endstreams(){ '
                .'echo \"'.self::STDOUT_EOF.'\$retStat\";echo \"'.self::STDERR_EOF.'\" >&2;exit; };'
                .'trap endstreams 0 2 3 6 15;bash \$1;retStat=\$?;rm \$1")';
            //echo self::$_runAndDeleteSh;
        }
    }

    public function __destruct(){
        // ensure we don't leave lingering ssh-agent processes
        if($this->_hasKeyring){
            $res = $this->run("eval `ssh-agent -k`");
            if($res['status'] === 0 ) $this->_hasKeyring = false;
        }

        //close resources
        //if(is_resource($this->_shellProc)) { var_export('Bash proc_get_status __destruct() ',proc_get_status($this->_shellProc)); }
        //close open pipes to avoid deadlock w/ proc_close()
        if(is_resource($this->_stdin))     { fclose($this->_stdin);  }
        if(is_resource($this->_stdout))    { fclose($this->_stdout); }
        if(is_resource($this->_stderr))    { fclose($this->_stderr); }
        //close the shell resource as we destruct to prevent apache from having zombie bash children
        if(is_resource($this->_shellProc)) { proc_close($this->_shellProc); }
    }

    /**
     * WARNING: COMMANDS CALLED HERE MUST EXECUTE 100% THRU AND NOT SHORT CIRCUIT OTHERWISE THE END OF STREAM DELIM WILL GET MISSED AND PHP WILL HANG
     *          THIS MEANS YOU CANNOT USE ANY OF THESE (&& , ; , \n) WITHIN $cmd OR ELSE THE EXIT STATUS WILL GET MISSED AND CAPTURING THE END OF STREAM MAY FAIL, WHICH WILL HANG PHP
     * Created for commands like ssh-agent which need to be resident at a higher level than normal sub shell which is the normal and more safe context of run()
     * @param string $cmd
     * @return Bash
     */
    public function runRoot($cmd, $background=false, $stdoutFn = NULL, $stderrFn = NULL){
        $this->_initRun($stdoutFn, $stderrFn);

        // mimic stream eof tokens like in $_runAndDeleteSh
        $outEOF = self::STDOUT_EOF;
        $errorEOF = self::STDERR_EOF;
        $bashCmd = "$cmd  ; echo \"$outEOF\$?\" ; echo \"$errorEOF\" >&2 ; \n";

        //echo "Executing this cmd via resident ROOT shell process:\n";
        //var_export(array('bash_cmd'=>$bashCmd,'orig_cmd'=>$cmd));

        fwrite($this->_stdin, $bashCmd);
        fflush($this->_stdin);

        $return = $this->_processCmdOutput($cmd, $background, $stdoutFn, $stderrFn);
        // echo "Bash response: \n" . var_export($return,true);
        return $return;
    }

    /**
     * Executes $cmd via a bash shell and seperates STDERR and STDOUT in seperate streams that can be attached to via callbacks
     * @param string $cmd The shell command to execute
     * @param bool $background If true will throw into background and return immediately.
     *        NOTE: If backgrounded then no useful data can be returned because the process will not have time to generate any.
     * @param string $tmpShName the name of the temp shell script created and run which contains $cmd.
     *               Useful to specify if tracking of the executed command is desired with some binary like ps or lsof
     * @param function $stdoutFn A function/closure object taking 1 string arg.
     *                 Called with increments of output from stdout as it is processed from shell $cmd
     *                 If used keep in mind that these partial output snippits WILL INCLUDE the program inserted end of stream tokens
     * @param function $stderrFn An function/closure object taking 1 string arg.
     *                 Called with increments of output from stderr as it is processed from shell $cmd
     *                 If used keep in mind that these partial output snippits WILL INCLUDE the program inserted end of stream tokens
     * @return array An array with four keys,
     * - ['command'] The command that was actually executed.
     * - ['status']  The integer exit status, any non 0 status means failure.
     * - ['output']  An array of lines of text returned from this commands STDOUT
     * - ['error']   An array of lines of text returned from this commands STDERR
     */
    public function run($cmd, $background=false, $stdoutFn = NULL, $stderrFn = NULL){
        $return = $this->_initRun($stdoutFn, $stderrFn);

        // wrap cmd in shell script to run in sub shell
        $tmpShName = microtime(true) .'-'. getmypid() .'-'. rand(0,42).".sh";
        $tmpSh = "/tmp/$tmpShName";
        $fp = fopen($tmpSh,'w+');
        fwrite($fp,$cmd);
        fclose($fp);

        $shCmd = "sh ". self::$_runAndDeleteSh ." $tmpSh";
        // for backgrounds processes stdout & stderr streams must be muted or else the process will not background
        if($background){
            $shCmd .= ' 2> /dev/null > /dev/null &';
        }
        //var_export('Executing this cmd via resident SUB shell process',array('sh_contents'=>$cmd,'sh_cmd'=>$shCmd));
        fwrite($this->_stdin, "$shCmd\n");
        fflush($this->_stdin);

        $return = $this->_processCmdOutput($cmd, $background, $stdoutFn, $stderrFn);

        //echo "Bash response: \n" . var_export($return,true);

        return $return;
    }

    private function _initRun($stdoutFn = NULL, $stderrFn = NULL){
        if($stdoutFn !== NULL && !is_callable($stdoutFn)){
           throw new Exception("Invalid stdout callback");
        }
        if($stderrFn !== NULL && !is_callable($stderrFn)){
            throw new Exception("Invalid stderr callback");
        }
        if($this->_firstRun){
            $this->_firstRun = false;
            // allow us to find the php binary, paths are specific to the zend server install
            $this->runRoot('export PATH=$PATH:/usr/local/bin:/usr/local/zend/bin');
        }
        if(empty($this->_stderr) || empty($this->_stdin) || empty($this->_stdout)){
            $descSpec = array(
                array('pipe','r'),  // stdin
                array('pipe','w'),  // stdout
                array('pipe','w')   // stderr
            );

            // export $_SERVER into this shell process
            $envInit = array();
            if($this->_exportEnv){
                foreach($_SERVER as $key=>$value){
                    if (is_string($value)){
                        $envInit['PARENT_SERVER_'.$key] = $value;
                    }
                }
                // add more to $envInit if needed here
            }

            $this->_shellProc = proc_open('bash', $descSpec, $pipes,'/tmp',$envInit);
            if(!is_resource($this->_shellProc)){ throw new Exception("Could not init shell process"); }
            $this->_stdin  = &$pipes[0];
            $this->_stdout = &$pipes[1];
            $this->_stderr = &$pipes[2];

            stream_set_blocking($this->_stdout,false);
            stream_set_blocking($this->_stderr,false);
        }
    }

    private function _processCmdOutput($cmd, $background=false, $stdoutFn = NULL, $stderrFn = NULL){
        $return = array(
            'output'    => array(),
            'error'     => array(),
            'command'   => $cmd,
            'status'    => 0
        );
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
                $strIn = fgets($this->_stderr,4096);
                // just interacting with a shell, all IO should be ascii
                // or binary is expected & parsed by the cmd author in the cmd
                $strIn = str_replace(array("\r\n","\r"),"\n",$strIn);
                if(!empty($strIn)) {
//                    echo "stderr read:\n$strIn\n";
                    $stderrBuff .= $strIn;
                    if($stderrFn !== NULL) {
                        $stderrSegment .= $strIn;
                        while(($pos = strpos($stderrSegment,"\n")) !== false){
                            $line =  substr($stderrSegment,0,$pos);
//                            echo "stdout calling callback with line:\n$line\n";
                            $stderrFn($line);
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
                $strIn = fgets($this->_stdout,4096);
                $strIn = str_replace(array("\r\n","\r"),"\n",$strIn);
                if(!empty($strIn)) {
//                    echo "stdout read:\n$strIn";
                    $stdoutBuff .= $strIn;
                    if($stdoutFn !== NULL) {
                       $stdoutSegment .= $strIn;
                        while(($pos = strpos($stdoutSegment,"\n")) !== false){
                            $line =  substr($stdoutSegment,0,$pos);
//                            echo "stdout calling callback with line:\n$line";
                            $stdoutFn($line);
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
        return $return;
    }

    /**
     * The keyring is ssh-agent and the key is added with ssh-add so these must be installed on the underlying system.
     * @param string $cert The absolute path to the location of the cert that will be added.
     * @param string $passphrase (optional) The passphrase for the key if one exists.
     * @throws Exception If the cert and password do not match.
     */
    public function addCertToKeyring($cert, $passphrase=NULL){
        // check cert type (unProtected|passphrsaeProtected)
        $res = $this->run("cat $cert | grep Proc-Type | grep ENCRYPTED");
        $isProtected = $res['status'] === 0 ? true : false;
        $hasPass = $passphrase !== NULL ? true : false;
        if((!$isProtected && $hasPass) ||  ($isProtected && !$hasPass)) {
            throw new Exception("Cert and Passphrase mismatch.  ONLY if the cert is passphrase protected should you pass in a passphrase");
        }

        // if cert is passphrase protected we need to supply the passphrase via an expect script
        $this->_startSshAgent();
        if($isProtected){
            $this->_addCertP($cert,$passphrase);
        }else{
            $this->_addCertNP($cert);
        }
    }

    /**
     * Attempts to runRoot('eval `ssh-agent`')
     * @throws Exception If the ssh-agent was unable to start.
     */
    private function _startSshAgent(){
        // it's running if we have the env var set & the process exists at the id stored in the env var
        $cmd = 'test -n "$SSH_AGENT_PID"  && ps -p  $SSH_AGENT_PID > /dev/null  2>&1';
        $res = $this->run($cmd);
        if($res['status'] !== 0){
            $this->runRoot("eval `ssh-agent`");
            $res = $this->run($cmd);
            if($res['status'] !== 0){
                throw new Exception("Unable to start ssh-agent.");
            }
            $this->_hasKeyring = true;
        }
    }

    /**
     * _addCertP is called if an openSSH key is passphrase protected
     * If the key is not an openSSH key, it is not detectable that it's passphrase protected
     * As such an attempt to add it to a keyring will hang waiting for a passphrase, this method will
     * return if the process waits for more than 2s so we avoid this hang.
     * @param string $cert The absolute path to the private cert
     * @throws Exception If the cert could not be added to the keyring
     */
    private function _addCertNP($cert){
        $scriptBody = <<<EXPECT_SCRIPT
# exp_internal 1  # debug flag
spawn ssh-add "$cert"
set timeout 2
expect {
    "Identity added*" { puts "Added cert to keyring successfully"; exit 0 }
    "Could not open a connection*" { puts "ssh-agent not started"; exit 1 }
    timeout { puts "Timed out adding cert to keyring."; exit 1 }
}
# there is one and only one case where we exit this script with a 0 status
exit 1
EXPECT_SCRIPT;
        $expectScript = "/usr/bin/expect -f <(echo -e '$scriptBody')";
        $res = $this->run($expectScript);
        if($res['status'] !== 0 ){
            throw new Exception("Unable to add cert $cert.  ".implode("\n",$res['output']));
        }
    }

    /**
     * If the passphrase is incorrect the add cert process can hang, wrap the call in an expect script here
     * so if the process takes more than 2s it returns.
     * @param string $cert The absolute path to the private cert
     * @param string $passphrase
     * @throws Exception
     */
    private function _addCertP($cert, $passphrase){
        //  echo "passphrase\n" | ssh-add    does not work because ssh-add does not read the passphrase from stdin instead it opens /dev/tty directly for reading
        //  if it takes more than 2s to add a cert to a keyring we have issues ... kill the process so we dont' hang ...

        // escape $ (expect vars start with $) , escape ' (we wrap our expect script in ')
        $passphrase = str_replace('$', '\$', $passphrase);
        $passphrase = str_replace("'", "\\'", $passphrase);
        $scriptBody = <<<EXPECT_SCRIPT
# exp_internal 1  # debug flag
spawn ssh-add "$cert"
set timeout 2
expect {
    "Enter passphrase*" {
      send -- "$passphrase\\n"
      expect {
          "Identity added*" { puts "Added cert to keyring successfully"; exit 0 }
          "Bad passphrase*" { puts "Invalid passphrase"; exit 1 }
          timeout { puts "Timed out attempting to read passphrase entry response."; exit 1 }
      }
    }
    "Could not open a connection*" { puts "ssh-agent not started"; exit 1 }
    timeout { puts "Timed out attempting to enter passphrase."; exit 1 }
}
# there is one and only one case where we exit this script with a 0 status
exit 1
EXPECT_SCRIPT;
        $expectScript = "/usr/bin/expect -f <(echo -e '$scriptBody')";
        $res = $this->run($expectScript);
        if($res['status'] !== 0 ){
            throw new Exception("Unable to enter passphrase for $cert.  ".implode("\n",$res['output']));
        }
    }

    /**
     * If called no environment info from this environment will be seeded into the child shell.
     * Useful when constructing that environment info (session id) would have an impact on this environment
     * @param $bool boolean If not a boolean will be ignored.
     */
    public function exportEnv($bool){
        if(is_bool($bool)){
            $this->_exportEnv = $bool;
        }
    }
}

