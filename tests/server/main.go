package main

import (
	"fmt"
	"net"
	"log"
	"os"
	"strings"
)


var clients map[int]net.Conn = make(map[int]net.Conn)
var clients_count int = 0
var msg_buffer string = ""
var msg_split string  = "\r\n\r\n\r\n";

func main() {

	//建立socket，监听端口
	listen, err := net.Listen("tcp", "localhost:9996")
	DealError(err)
	defer listen.Close()

	Log("Waiting for clients")
	for {
		conn, err := listen.Accept()

		if err != nil {
			continue
		}
		go OnConnect(conn)
	}
}


func AddClient(conn net.Conn) {
	clients[clients_count] = conn
	clients_count++
}

func RemoveClient(conn net.Conn){
	// 遍历map
	for k, v := range clients {
		if v == conn {
			delete(clients, k)
		}
	}
	clients_count--
}


/**
 * 广播
 *
 * @param string msg
 */
func Broadcast(msg string) {
	for _, v := range clients {
		go v.Write([]byte(msg))
	}
}


//处理连接
func OnConnect(conn net.Conn) {
	Log(conn.RemoteAddr().String(), " tcp connect success")
	go AddClient(conn)
	buffer := make([]byte, 2048)

	for {

		n, err := conn.Read(buffer)

		if err != nil {
			Log(conn.RemoteAddr().String(), " connection error: ", err)


			onClose(conn);
			conn.Close();

			return
		}


		Log(conn.RemoteAddr().String(), "receive data string:\n", string(buffer[:n]))
		go OnMessage(conn, string(buffer[:n]))
	}

}

func OnMessage(conn net.Conn, msg string) {
	//html := 		"HTTP/1.1 200 OK\r\nContent-Length: 5\r\nContent-Type: text/html\r\n\r\nhello"
	msg_buffer += msg
	//Broadcast(msg);
	//粘包处理
	temp := strings.Split(msg_buffer, msg_split)
	temp_len := len(temp)
	if (temp_len >= 2) {
		msg_buffer = temp[temp_len - 1];
		for _, v := range temp {
			if strings.EqualFold(v, "") {
				continue
			}
			Broadcast(v);
		}
		//foreach ($temp as $v) {
		//if (!$v) {
		//continue;
		//}
		//$count++;
		//echo $v, "\r\n";
		//echo "收到消息次数：", $count, "\r\n\r\n";
		//}
		//}
		//unset($temp);

	}
}

func onClose(conn net.Conn) {
	RemoveClient(conn)
}

func Log(v ...interface{}) {
	log.Println(v...)
}

func DealError(err error) {
	if err != nil {
		fmt.Fprintf(os.Stderr, "Fatal error: %s", err.Error())
		os.Exit(1)
	}
}