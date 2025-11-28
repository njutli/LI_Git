

[Linux 通用块层之IO合并](https://mp.weixin.qq.com/s?__biz=Mzg2OTc0ODAzMw==&mid=2247502091&idx=1&sn=68b81ad43c3e54f03771d7fb05069444&source=41&poc_token=HBTIJ2mj_oXQ54vboFJqkhWQ7QRxRS2EaWdTgfVf)

1. plug/unplug: 当前进程下的IO不是只有一个吗，为什么要plug<br>
当前进程对dm设备下发一个大IO，在dm层可能会依据map拆分成不同设备上的小IO，这些IO都会被submit。在dm设备堆叠的场景下，可能会出现用户态下发的一个大IO，拆分成很多小IO，而这些小IO可能是发往同一个设备，可以合并的IO，不应该对每个IO都生成request直接下发，而是通过plug暂存，再通过request级别的合并来减少向底层设备发送IO的次数，以提高效率
