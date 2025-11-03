lilingfeng@localhost:~/code2/new_openeuler/kernel/ko_test/kobject_demo$ pwd
/home/lilingfeng/code2/new_openeuler/kernel/ko_test/kobject_demo
lilingfeng@localhost:~/code2/new_openeuler/kernel/ko_test/kobject_demo$ cat Makefile
#Makefile 2.6

obj-m :=kobject_demo.o
KERNEL :=../..
PWD :=$(shell pwd)
modules :
        $(MAKE) -C $(KERNEL) M=$(PWD) modules
.PHONEY:clean
clean :
        rm -f *.o *.ko

lilingfeng@localhost:~/code2/new_openeuler/kernel/ko_test/kobject_demo$ ls
kobject_demo.c   kobject_demo.mod    kobject_demo.mod.o  Makefile       Module.symvers
kobject_demo.ko  kobject_demo.mod.c  kobject_demo.o      modules.order
lilingfeng@localhost:~/code2/new_openeuler/kernel/ko_test/kobject_demo$
