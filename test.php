<?php

include 'Bash.php';

$bash = new Bash(); 

$stdout_cb = function($line) { 
    // actually the last stdout line is Bash::STDOUT_EOF/return_status
    // which is why strpos is better than an exact string compare
    if(strpos($line,Bash::STDOUT_EOF) !== 0) {
        print "<<stdout callback>>  $line\n";
    }
};

$stderr_cb = function($line) { 
    if(strpos($line,Bash::STDERR_EOF) !== 0) {
        print "<<stderr callback>>  $line\n";
    }
};

# demo callback
$result = $bash->run('echo "Hello from Bash()"',false,$stdout_cb,$stderr_cb);
spew($result);
$result = $bash->run('ls fileNotFound',false,$stdout_cb,$stderr_cb);
spew($result);

# vanilla run
$result = $bash->run('echo "vanilla run simple output"');
spew($result);
# background run (no output)
$result = $bash->run('sleep 10',true);
spew($result);

#test bash specific functionality
$result = $bash->run('cat <(echo "contents of a file")');
print "Bash specific <() operator test:\n";
spew($result);


function spew($result){
    print "(status) '". $result['status'] ."'\n";
    print "(command)'". $result['command'] ."'\n";
    print "(stdout) '". implode("\n",$result['output']) ."'\n";
    print "(stderr) '". implode("\n",$result['error']) ."'\n\n";
}
