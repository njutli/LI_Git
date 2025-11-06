```
ulimit -l unlimited
clang -O2 -target bpf -c bpf_simple.c -o bpf_simple.o
./bpf_loader_v2 bpf_simple.o
```
