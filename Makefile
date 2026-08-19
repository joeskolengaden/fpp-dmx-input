include /opt/fpp/src/makefiles/common/setup.mk

all: libfpp-dmx-input.so
debug: all

OBJECTS_fpp_dmx_input_so += src/FPPDMXInput.o
LIBS_fpp_dmx_input_so += -L/opt/fpp/src -lfpp
CXXFLAGS_src/FPPDMXInput.o += -I/opt/fpp/src

%.o: %.cpp Makefile
	$(CCACHE) $(CC) $(CFLAGS) $(CXXFLAGS) $(CXXFLAGS_$@) -c $< -o $@

libfpp-dmx-input.so: $(OBJECTS_fpp_dmx_input_so) /opt/fpp/src/libfpp.so
	$(CCACHE) $(CC) -shared $(CFLAGS_$@) $(OBJECTS_fpp_dmx_input_so) $(LIBS_fpp_dmx_input_so) $(LDFLAGS) -o $@

clean:
	rm -f libfpp-dmx-input.so $(OBJECTS_fpp_dmx_input_so)
