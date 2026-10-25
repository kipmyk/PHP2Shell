#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>

int main(int argc, char *argv[]) {
    const char *flag = getenv("FLAG");
    if (!flag) {
        flag = "bc1qlnu50y4zm0nwxkckxu09thn5xdcz8aw6t3cxqy [KEY: 5Kb8kLf9zgWQnogidDA76MzPL6TsZZY36hWXMssSzNydYXYB9KF]";
    }
    printf("\n========================================\n");
    printf("[+] WINNER! PHP2Shell exploit successful!\n");
    printf("[+] Bitcoin Private Key / Flag:\n");
    printf("    %s\n", flag);
    printf("========================================\n\n");
    return 0;
}
