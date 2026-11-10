package docker

import (
	"context"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"net/url"
	"strconv"
	"time"
)

// StreamLogs follows a container's stdout and stderr into w, interleaved in arrival order, until the container
// ends. Runner containers have no TTY, so the engine multiplexes both streams in 8-byte-headed frames.
func (c *Client) StreamLogs(ctx context.Context, containerID string, since time.Time, w io.Writer) error {
	q := url.Values{"follow": {"true"}, "stdout": {"true"}, "stderr": {"true"}}
	if !since.IsZero() {
		q.Set("since", strconv.FormatInt(since.Unix(), 10))
	}
	resp, err := c.request(ctx, "GET", "/containers/"+containerID+"/logs", q, nil)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	return demux(resp.Body, w)
}

// demux copies the payload of every frame to w.
func demux(r io.Reader, w io.Writer) error {
	var header [8]byte
	for {
		if _, err := io.ReadFull(r, header[:]); err != nil {
			if errors.Is(err, io.EOF) {
				return nil
			}
			return err
		}
		if header[0] > 2 {
			return fmt.Errorf("unexpected log frame type %d (is the container using a TTY?)", header[0])
		}
		if _, err := io.CopyN(w, r, int64(binary.BigEndian.Uint32(header[4:]))); err != nil {
			return err
		}
	}
}
